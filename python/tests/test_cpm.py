from uuid import UUID

import pytest
from fastapi.testclient import TestClient


def test_sequential_tasks_form_eight_day_critical_path(
    client: TestClient, cpm_payload: dict
) -> None:
    response = client.post("/api/cpm/analyze", json=cpm_payload)
    assert response.status_code == 200
    data = response.json()
    assert data["project_duration"] == "8.00000000"
    assert data["critical_paths"] == [[task["task_id"] for task in cpm_payload["tasks"]]]
    assert data["critical_paths_truncated"] is False
    assert [task["early_start"] for task in data["results"]] == [
        "0.00000000",
        "2.00000000",
        "7.00000000",
    ]
    assert all(task["is_critical"] and task["slack"] == "0.00000000" for task in data["results"])


def test_parallel_branch_has_float_and_backward_pass(client: TestClient, cpm_payload: dict) -> None:
    first, second, third = [task["task_id"] for task in cpm_payload["tasks"]]
    cpm_payload["dependencies"] = [
        {"predecessor_task_id": first, "successor_task_id": second},
        {"predecessor_task_id": first, "successor_task_id": third},
    ]
    response = client.post("/api/cpm/analyze", json=cpm_payload)
    assert response.status_code == 200
    data = response.json()
    result = next(task for task in data["results"] if task["task_id"] == third)
    assert data["project_duration"] == "7.00000000"
    assert data["critical_paths"] == [[first, second]]
    assert result == {
        "task_id": third,
        "early_start": "2.00000000",
        "early_finish": "3.00000000",
        "late_start": "6.00000000",
        "late_finish": "7.00000000",
        "slack": "4.00000000",
        "is_critical": False,
    }


def test_diamond_returns_both_critical_paths(client: TestClient, cpm_payload: dict) -> None:
    first, second, third = [task["task_id"] for task in cpm_payload["tasks"]]
    last = str(UUID(int=13))
    cpm_payload["tasks"][2]["duration_days"] = "5"
    cpm_payload["tasks"].append({"task_id": last, "duration_days": "1"})
    cpm_payload["dependencies"] = [
        {"predecessor_task_id": source, "successor_task_id": target}
        for source, target in [(first, second), (first, third), (second, last), (third, last)]
    ]
    response = client.post("/api/cpm/analyze", json=cpm_payload)
    assert response.status_code == 200
    data = response.json()
    assert data["project_duration"] == "8.00000000"
    assert data["critical_paths"] == [[first, second, last], [first, third, last]]


def test_disconnected_tasks_use_common_project_finish(
    client: TestClient, cpm_payload: dict
) -> None:
    cpm_payload["dependencies"] = []
    data = client.post("/api/cpm/analyze", json=cpm_payload).json()
    assert data["project_duration"] == "5.00000000"
    assert [task["slack"] for task in data["results"]] == ["3.00000000", "0.00000000", "4.00000000"]


def test_decimal_durations_do_not_create_false_slack(client: TestClient, cpm_payload: dict) -> None:
    for task, duration in zip(cpm_payload["tasks"], ["0.1", "0.2", "0.3"], strict=True):
        task["duration_days"] = duration
    data = client.post("/api/cpm/analyze", json=cpm_payload).json()
    assert data["project_duration"] == "0.60000000"
    assert all(task["is_critical"] for task in data["results"])


@pytest.mark.parametrize("invalid", ["cycle", "self", "missing", "duplicate", "duplicate_task"])
def test_invalid_graph_rejected(client: TestClient, cpm_payload: dict, invalid: str) -> None:
    first, _, last = [task["task_id"] for task in cpm_payload["tasks"]]
    edges = {
        "cycle": {"predecessor_task_id": last, "successor_task_id": first},
        "self": {"predecessor_task_id": first, "successor_task_id": first},
        "missing": {"predecessor_task_id": str(UUID(int=999)), "successor_task_id": first},
        "duplicate": cpm_payload["dependencies"][0],
    }
    if invalid == "duplicate_task":
        cpm_payload["tasks"].append(cpm_payload["tasks"][0].copy())
    else:
        cpm_payload["dependencies"].append(edges[invalid])
    assert client.post("/api/cpm/analyze", json=cpm_payload).status_code == 422


def test_result_is_deterministic_when_input_order_changes(
    client: TestClient, cpm_payload: dict
) -> None:
    expected = client.post("/api/cpm/analyze", json=cpm_payload).json()
    cpm_payload["tasks"].reverse()
    cpm_payload["dependencies"].reverse()
    assert client.post("/api/cpm/analyze", json=cpm_payload).json() == expected


def test_long_chain_does_not_recurse(client: TestClient, cpm_payload: dict) -> None:
    task_ids = [str(UUID(int=index + 10)) for index in range(1500)]
    cpm_payload["tasks"] = [{"task_id": task_id, "duration_days": "1"} for task_id in task_ids]
    cpm_payload["dependencies"] = [
        {"predecessor_task_id": predecessor, "successor_task_id": successor}
        for predecessor, successor in zip(task_ids, task_ids[1:], strict=False)
    ]
    response = client.post("/api/cpm/analyze", json=cpm_payload)
    assert response.status_code == 200
    assert response.json()["critical_paths"] == [task_ids]
    assert response.json()["project_duration"] == "1500.00000000"


def test_many_critical_paths_are_explicitly_truncated(
    client: TestClient, cpm_payload: dict
) -> None:
    layers = [[str(UUID(int=10 + layer * 2 + node)) for node in range(2)] for layer in range(11)]
    cpm_payload["tasks"] = [
        {"task_id": task_id, "duration_days": "1"} for layer in layers for task_id in layer
    ]
    cpm_payload["dependencies"] = [
        {"predecessor_task_id": predecessor, "successor_task_id": successor}
        for previous, current in zip(layers, layers[1:], strict=False)
        for predecessor in previous
        for successor in current
    ]
    response = client.post("/api/cpm/analyze", json=cpm_payload)
    assert response.status_code == 200
    data = response.json()
    assert data["project_duration"] == "11.00000000"
    assert data["critical_paths_truncated"] is True
    assert len(data["critical_paths"]) == 1000
    assert len(data["results"]) == 22


def test_long_branching_paths_respect_total_node_limit(
    client: TestClient, cpm_payload: dict
) -> None:
    chain = [str(UUID(int=index + 10)) for index in range(1000)]
    leaves = [str(UUID(int=index + 1010)) for index in range(100)]
    cpm_payload["tasks"] = [
        {"task_id": task_id, "duration_days": "1"} for task_id in [*chain, *leaves]
    ]
    cpm_payload["dependencies"] = [
        {"predecessor_task_id": predecessor, "successor_task_id": successor}
        for predecessor, successor in zip(chain, chain[1:], strict=False)
    ] + [{"predecessor_task_id": chain[-1], "successor_task_id": leaf} for leaf in leaves]
    response = client.post("/api/cpm/analyze", json=cpm_payload)
    assert response.status_code == 200
    data = response.json()
    assert data["critical_paths_truncated"] is True
    assert len(data["critical_paths"]) == 99
    assert len(data["results"]) == 1100
    assert data["project_duration"] == "1001.00000000"
