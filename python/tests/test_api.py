import pytest
from fastapi.testclient import TestClient

from analysis.main import create_app


@pytest.mark.parametrize("endpoint", ["pert", "cpm", "evm"])
def test_analysis_requires_api_key(client: TestClient, endpoint: str) -> None:
    del client.headers["X-API-Key"]
    assert client.post(f"/api/{endpoint}/analyze", json={}).status_code == 401
    assert (
        client.post(
            f"/api/{endpoint}/analyze", headers={"X-API-Key": "incorrect"}, json={}
        ).status_code
        == 401
    )


@pytest.mark.parametrize("api_key", ["", "short", " " * 32])
def test_startup_rejects_missing_or_weak_key(monkeypatch: pytest.MonkeyPatch, api_key: str) -> None:
    monkeypatch.setenv("ANALYSIS_API_KEY", api_key)
    with pytest.raises(RuntimeError, match="ANALYSIS_API_KEY"), TestClient(create_app()):
        pass


def test_health_and_openapi_contract(client: TestClient) -> None:
    assert client.get("/health").json() == {"status": "ok", "engine_version": "1.0.0"}
    schema = client.get("/openapi.json").json()
    for endpoint in ("pert", "cpm", "evm"):
        operation = schema["paths"][f"/api/{endpoint}/analyze"]["post"]
        assert operation["security"] == [{"APIKeyHeader": []}]
        assert "200" in operation["responses"]
    assert schema["components"]["securitySchemes"]["APIKeyHeader"]["name"] == "X-API-Key"
    assert client.get("/docs").status_code == 200


def test_docs_disabled_by_default(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setenv("ANALYSIS_API_KEY", "test-analysis-key-0123456789abcdef")
    monkeypatch.delenv("ANALYSIS_DOCS_ENABLED", raising=False)
    with TestClient(create_app()) as client:
        assert client.get("/docs").status_code == 404
        assert client.get("/openapi.json").status_code == 404


def test_invalid_json_returns_validation_error(client: TestClient) -> None:
    response = client.post(
        "/api/pert/analyze", content="{broken", headers={"Content-Type": "application/json"}
    )
    assert response.status_code == 422


def test_validation_rejects_unknown_fields(client: TestClient, pert_payload: dict) -> None:
    pert_payload["tasks"][0]["duration_days"] = "20"
    assert client.post("/api/pert/analyze", json=pert_payload).status_code == 422


def test_invalid_uuid_rejected(client: TestClient, pert_payload: dict) -> None:
    pert_payload["project_id"] = "not-a-uuid"
    assert client.post("/api/pert/analyze", json=pert_payload).status_code == 422
