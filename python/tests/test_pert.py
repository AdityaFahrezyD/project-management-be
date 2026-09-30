import pytest
from fastapi.testclient import TestClient


def test_pert_formula_and_snapshot(client: TestClient, pert_payload: dict) -> None:
    response = client.post("/api/pert/analyze", json=pert_payload)
    assert response.status_code == 200
    data = response.json()
    assert data["project_id"] == pert_payload["project_id"]
    assert data["engine_version"] == "1.0.0"
    assert data["results"] == [
        {
            "task_id": pert_payload["tasks"][0]["task_id"],
            "optimistic_time": "2.00000000",
            "most_likely_time": "5.00000000",
            "pessimistic_time": "8.00000000",
            "expected_time": "5.00000000",
            "variance": "1.00000000",
            "standard_deviation": "1.00000000",
        }
    ]


def test_pert_repeating_decimal(client: TestClient, pert_payload: dict) -> None:
    pert_payload["tasks"][0].update(optimistic_time="1", most_likely_time="2", pessimistic_time="4")
    response = client.post("/api/pert/analyze", json=pert_payload)
    assert response.status_code == 200
    result = response.json()["results"][0]
    assert result["expected_time"] == "2.16666667"
    assert result["variance"] == "0.25000000"
    assert result["standard_deviation"] == "0.50000000"


def test_equal_estimates_have_no_variance(client: TestClient, pert_payload: dict) -> None:
    pert_payload["tasks"][0].update(
        optimistic_time="0.0001", most_likely_time="0.0001", pessimistic_time="0.0001"
    )
    result = client.post("/api/pert/analyze", json=pert_payload).json()["results"][0]
    assert result["expected_time"] == "0.00010000"
    assert result["variance"] == "0.00000000"


@pytest.mark.parametrize(
    "value", ["0", "-1", "NaN", "Infinity", "1e999", "100000000", "0.00001", True, None]
)
def test_invalid_durations_rejected(client: TestClient, pert_payload: dict, value: object) -> None:
    pert_payload["tasks"][0]["optimistic_time"] = value
    assert client.post("/api/pert/analyze", json=pert_payload).status_code == 422


def test_raw_nan_does_not_crash_validation(client: TestClient, pert_payload: dict) -> None:
    import json

    body = json.dumps(pert_payload).replace('"optimistic_time": "2"', '"optimistic_time": NaN')
    response = client.post(
        "/api/pert/analyze", content=body, headers={"Content-Type": "application/json"}
    )
    assert response.status_code == 422


def test_unordered_estimates_rejected(client: TestClient, pert_payload: dict) -> None:
    pert_payload["tasks"][0]["optimistic_time"] = "6"
    assert client.post("/api/pert/analyze", json=pert_payload).status_code == 422


def test_duplicate_tasks_rejected(client: TestClient, pert_payload: dict) -> None:
    pert_payload["tasks"].append(pert_payload["tasks"][0].copy())
    assert client.post("/api/pert/analyze", json=pert_payload).status_code == 422


def test_empty_tasks_rejected(client: TestClient, pert_payload: dict) -> None:
    pert_payload["tasks"] = []
    assert client.post("/api/pert/analyze", json=pert_payload).status_code == 422
