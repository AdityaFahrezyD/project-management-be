from copy import deepcopy
from uuid import UUID

import pytest
from fastapi.testclient import TestClient


def test_prorated_pv_and_forecasts(client: TestClient, evm_payload: dict) -> None:
    response = client.post("/api/evm/analyze", json=evm_payload)
    assert response.status_code == 200
    data = response.json()
    assert data["project_baseline_id"] == evm_payload["project_baseline_id"]
    assert data["analysis_date"] == "2026-09-05"
    assert data["budget_at_completion"] == "1000.00000000"
    assert data["warnings"] == []
    assert data["result"] == {
        "planned_value": "500.00000000",
        "earned_value": "500.00000000",
        "actual_cost": "400.00000000",
        "cost_variance": "100.00000000",
        "schedule_variance": "0.00000000",
        "cost_performance_index": "1.25000000",
        "schedule_performance_index": "1.00000000",
        "estimate_at_completion": "800.00000000",
        "estimate_to_complete": "400.00000000",
        "variance_at_completion": "200.00000000",
        "to_complete_performance_index": "0.83333333",
    }


@pytest.mark.parametrize(
    ("analysis_date", "planned"),
    [
        ("2026-08-31", "0.00000000"),
        ("2026-09-01", "100.00000000"),
        ("2026-09-10", "1000.00000000"),
        ("2026-09-20", "1000.00000000"),
    ],
)
def test_pv_inclusive_period_boundaries(
    client: TestClient, evm_payload: dict, analysis_date: str, planned: str
) -> None:
    evm_payload["analysis_date"] = analysis_date
    response = client.post("/api/evm/analyze", json=evm_payload)
    assert response.status_code == 200
    assert response.json()["result"]["planned_value"] == planned


def test_all_status_completion_values(client: TestClient, evm_payload: dict) -> None:
    evm_payload["tasks"] = [
        {"task_id": str(UUID(int=10 + index)), "budget_amount": "250", "status": status}
        for index, status in enumerate(["todo", "in_progress", "review", "done"])
    ]
    response = client.post("/api/evm/analyze", json=evm_payload)
    assert response.status_code == 200
    assert response.json()["result"]["earned_value"] == "562.50000000"


def test_future_expenses_excluded(client: TestClient, evm_payload: dict) -> None:
    evm_payload["expenses"].append(
        {
            "expense_id": str(UUID(int=101)),
            "expense_date": "2026-09-06",
            "amount": "999",
            "currency": "IDR",
        }
    )
    response = client.post("/api/evm/analyze", json=evm_payload)
    assert response.status_code == 200
    assert response.json()["result"]["actual_cost"] == "400.00000000"


def test_zero_denominators_return_null_and_warnings(client: TestClient, evm_payload: dict) -> None:
    evm_payload["analysis_date"] = "2026-08-31"
    evm_payload["tasks"][0]["status"] = "todo"
    evm_payload["expenses"] = []
    response = client.post("/api/evm/analyze", json=evm_payload)
    assert response.status_code == 200
    data = response.json()
    for metric in [
        "cost_performance_index",
        "schedule_performance_index",
        "estimate_at_completion",
        "estimate_to_complete",
        "variance_at_completion",
    ]:
        assert data["result"][metric] is None
    assert data["result"]["to_complete_performance_index"] == "1.00000000"
    assert len(data["warnings"]) == 3
    assert "NaN" not in response.text and "Infinity" not in response.text


def test_zero_earned_value_with_actual_cost_has_no_forecast(
    client: TestClient, evm_payload: dict
) -> None:
    evm_payload["tasks"][0]["status"] = "todo"
    result = client.post("/api/evm/analyze", json=evm_payload).json()["result"]
    assert result["cost_performance_index"] == "0.00000000"
    assert result["estimate_at_completion"] is None


@pytest.mark.parametrize("actual", ["1000", "1200"])
def test_tcpi_undefined_when_budget_is_exhausted(
    client: TestClient, evm_payload: dict, actual: str
) -> None:
    evm_payload["expenses"][0]["amount"] = actual
    response = client.post("/api/evm/analyze", json=evm_payload)
    assert response.status_code == 200
    assert response.json()["result"]["to_complete_performance_index"] is None
    assert response.json()["warnings"]


def test_zero_budget_is_valid(client: TestClient, evm_payload: dict) -> None:
    evm_payload["tasks"][0]["budget_amount"] = "0"
    evm_payload["budget_periods"][0]["planned_amount"] = "0"
    evm_payload["expenses"] = []
    response = client.post("/api/evm/analyze", json=evm_payload)
    assert response.status_code == 200
    assert response.json()["budget_at_completion"] == "0.00000000"
    assert response.json()["result"]["to_complete_performance_index"] is None


def test_single_day_period(client: TestClient, evm_payload: dict) -> None:
    evm_payload["budget_periods"][0].update(period_start="2026-09-05", period_end="2026-09-05")
    result = client.post("/api/evm/analyze", json=evm_payload).json()["result"]
    assert result["planned_value"] == "1000.00000000"


def test_multiple_periods_and_gaps(client: TestClient, evm_payload: dict) -> None:
    evm_payload["budget_periods"] = [
        {"period_start": "2026-09-01", "period_end": "2026-09-02", "planned_amount": "200"},
        {"period_start": "2026-09-04", "period_end": "2026-09-07", "planned_amount": "800"},
    ]
    result = client.post("/api/evm/analyze", json=evm_payload).json()["result"]
    assert result["planned_value"] == "600.00000000"


def test_large_money_keeps_decimal_precision(client: TestClient, evm_payload: dict) -> None:
    evm_payload["tasks"][0].update(budget_amount="999999999999.9999", status="done")
    evm_payload["budget_periods"][0]["planned_amount"] = "999999999999.9999"
    evm_payload["expenses"][0]["amount"] = "999999999999.9998"
    evm_payload["analysis_date"] = "2026-09-10"
    result = client.post("/api/evm/analyze", json=evm_payload).json()["result"]
    assert result["earned_value"] == "999999999999.99990000"
    assert result["cost_variance"] == "0.00010000"


def test_prorating_rounds_only_the_final_total(client: TestClient, evm_payload: dict) -> None:
    evm_payload["tasks"][0]["budget_amount"] = "1"
    evm_payload["budget_periods"][0].update(period_end="2026-09-03", planned_amount="1")
    evm_payload["analysis_date"] = "2026-09-01"
    evm_payload["expenses"] = []
    result = client.post("/api/evm/analyze", json=evm_payload).json()["result"]
    assert result["planned_value"] == "0.33333333"


@pytest.mark.parametrize(
    "invalid",
    [
        "negative_budget",
        "negative_expense",
        "zero_expense",
        "invalid_status",
        "manual_progress",
        "currency",
        "duplicate_task",
        "duplicate_expense",
        "period_order",
        "period_overlap",
        "budget_mismatch",
        "empty_tasks",
        "empty_periods",
        "invalid_date",
    ],
)
def test_invalid_evm_input_rejected(client: TestClient, evm_payload: dict, invalid: str) -> None:
    if invalid == "negative_budget":
        evm_payload["tasks"][0]["budget_amount"] = "-1"
    elif invalid == "negative_expense":
        evm_payload["expenses"][0]["amount"] = "-1"
    elif invalid == "zero_expense":
        evm_payload["expenses"][0]["amount"] = "0"
    elif invalid == "invalid_status":
        evm_payload["tasks"][0]["status"] = "cancelled"
    elif invalid == "manual_progress":
        evm_payload["tasks"][0]["completion"] = 0.4
    elif invalid == "currency":
        evm_payload["expenses"][0]["currency"] = "USD"
    elif invalid == "duplicate_task":
        evm_payload["tasks"].append(evm_payload["tasks"][0].copy())
    elif invalid == "duplicate_expense":
        evm_payload["expenses"].append(evm_payload["expenses"][0].copy())
    elif invalid == "period_order":
        evm_payload["budget_periods"][0]["period_end"] = "2026-08-31"
    elif invalid == "period_overlap":
        evm_payload["budget_periods"].append(evm_payload["budget_periods"][0].copy())
        for period in evm_payload["budget_periods"]:
            period["planned_amount"] = "500"
    elif invalid == "budget_mismatch":
        evm_payload["budget_periods"][0]["planned_amount"] = "999"
    elif invalid == "empty_tasks":
        evm_payload["tasks"] = []
    elif invalid == "empty_periods":
        evm_payload["budget_periods"] = []
    elif invalid == "invalid_date":
        evm_payload["analysis_date"] = "2026-02-30"
    assert client.post("/api/evm/analyze", json=evm_payload).status_code == 422


def test_unpersistable_forecast_rejected(client: TestClient, evm_payload: dict) -> None:
    evm_payload["tasks"][0].update(budget_amount="9999999999999999", status="done")
    evm_payload["budget_periods"][0]["planned_amount"] = "9999999999999999"
    evm_payload["expenses"][0]["amount"] = "0.0001"
    response = client.post("/api/evm/analyze", json=evm_payload)
    assert response.status_code == 422
    assert "storage limit" in response.json()["detail"]


def test_repeated_request_is_reproducible(client: TestClient, evm_payload: dict) -> None:
    original = deepcopy(evm_payload)
    first = client.post("/api/evm/analyze", json=evm_payload)
    second = client.post("/api/evm/analyze", json=evm_payload)
    assert first.status_code == second.status_code == 200
    assert first.json() == second.json()
    assert evm_payload == original
