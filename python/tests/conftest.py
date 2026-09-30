from collections.abc import Iterator
from uuid import UUID

import pytest
from fastapi.testclient import TestClient

from analysis.main import create_app

API_KEY = "test-analysis-key-0123456789abcdef"


def identifier(value: int) -> str:
    return str(UUID(int=value))


@pytest.fixture
def client(monkeypatch: pytest.MonkeyPatch) -> Iterator[TestClient]:
    monkeypatch.setenv("ANALYSIS_API_KEY", API_KEY)
    monkeypatch.setenv("ANALYSIS_DOCS_ENABLED", "true")
    with TestClient(create_app(), headers={"X-API-Key": API_KEY}) as test_client:
        yield test_client


@pytest.fixture
def pert_payload() -> dict:
    return {
        "project_id": identifier(1),
        "tasks": [
            {
                "task_id": identifier(10),
                "optimistic_time": "2",
                "most_likely_time": "5",
                "pessimistic_time": "8",
            }
        ],
    }


@pytest.fixture
def cpm_payload() -> dict:
    return {
        "project_id": identifier(1),
        "tasks": [
            {"task_id": identifier(10), "duration_days": "2"},
            {"task_id": identifier(11), "duration_days": "5"},
            {"task_id": identifier(12), "duration_days": "1"},
        ],
        "dependencies": [
            {"predecessor_task_id": identifier(10), "successor_task_id": identifier(11)},
            {"predecessor_task_id": identifier(11), "successor_task_id": identifier(12)},
        ],
    }


@pytest.fixture
def evm_payload() -> dict:
    return {
        "project_id": identifier(1),
        "project_baseline_id": identifier(2),
        "analysis_date": "2026-09-05",
        "currency": "IDR",
        "tasks": [
            {
                "task_id": identifier(10),
                "budget_amount": "1000",
                "status": "in_progress",
            }
        ],
        "budget_periods": [
            {
                "period_start": "2026-09-01",
                "period_end": "2026-09-10",
                "planned_amount": "1000",
            }
        ],
        "expenses": [
            {
                "expense_id": identifier(100),
                "expense_date": "2026-09-05",
                "amount": "400",
                "currency": "IDR",
            }
        ],
    }
