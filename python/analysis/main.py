import os
from collections.abc import AsyncIterator
from contextlib import asynccontextmanager
from secrets import compare_digest
from typing import Annotated

from fastapi import APIRouter, Depends, FastAPI, HTTPException, Request, Security
from fastapi.exceptions import RequestValidationError
from fastapi.responses import JSONResponse
from fastapi.security import APIKeyHeader

from analysis import ENGINE_VERSION
from analysis.common import AnalysisError
from analysis.cpm import analyze_cpm
from analysis.evm import analyze_evm
from analysis.pert import analyze_pert
from analysis.schemas import (
    CpmRequest,
    CpmResponse,
    EvmRequest,
    EvmResponse,
    PertRequest,
    PertResponse,
)

api_key_header = APIKeyHeader(name="X-API-Key", auto_error=False)


@asynccontextmanager
async def lifespan(app: FastAPI) -> AsyncIterator[None]:
    api_key = os.environ.get("ANALYSIS_API_KEY", "")
    if len(api_key.strip()) < 32:
        raise RuntimeError("Set ANALYSIS_API_KEY to a shared secret of at least 32 characters.")
    app.state.api_key = api_key.encode("utf-8")
    yield


def require_api_key(
    request: Request,
    api_key: Annotated[str | None, Security(api_key_header)],
) -> None:
    if api_key is None or not compare_digest(api_key.encode("utf-8"), request.app.state.api_key):
        raise HTTPException(
            status_code=401,
            detail="Invalid or missing service API key.",
            headers={"WWW-Authenticate": "APIKey"},
        )


def create_app() -> FastAPI:
    docs_enabled = os.environ.get("ANALYSIS_DOCS_ENABLED", "false").lower() == "true"
    app = FastAPI(
        title="Project Management Analysis",
        version=ENGINE_VERSION,
        description=(
            "Internal calculation service for Laravel. Results use decimal strings with eight "
            "decimal places. Laravel supplies authorized, project-scoped snapshots and owns "
            "analysis versioning and persistence. Send leaf tasks only; EVM statuses must reflect "
            "analysis_date. All EVM amounts use one baseline currency."
        ),
        lifespan=lifespan,
        docs_url="/docs" if docs_enabled else None,
        redoc_url=None,
        openapi_url="/openapi.json" if docs_enabled else None,
    )

    @app.exception_handler(AnalysisError)
    async def analysis_error(request: Request, exception: AnalysisError) -> JSONResponse:
        return JSONResponse(status_code=422, content={"detail": str(exception)})

    @app.exception_handler(RequestValidationError)
    async def validation_error(request: Request, exception: RequestValidationError) -> JSONResponse:
        errors = [
            {"loc": error["loc"], "msg": error["msg"], "type": error["type"]}
            for error in exception.errors()
        ]
        return JSONResponse(status_code=422, content={"detail": errors})

    @app.get("/health", tags=["Health"])
    def health() -> dict[str, str]:
        return {"status": "ok", "engine_version": ENGINE_VERSION}

    router = APIRouter(prefix="/api", dependencies=[Depends(require_api_key)])

    @router.post("/pert/analyze", response_model=PertResponse, tags=["PERT"])
    def pert(payload: PertRequest) -> PertResponse:
        """Calculate duration estimates without changing PM planned durations."""
        return analyze_pert(payload)

    @router.post("/cpm/analyze", response_model=CpmResponse, tags=["CPM"])
    def cpm(payload: CpmRequest) -> CpmResponse:
        """Use finish-to-start dependencies, 24-hour days, no work calendar or lag."""
        return analyze_cpm(payload)

    @router.post("/evm/analyze", response_model=EvmResponse, tags=["EVM"])
    def evm(payload: EvmRequest) -> EvmResponse:
        """Prorate PV by inclusive calendar days; EAC=BAC/CPI, ETC=EAC-AC, VAC=BAC-EAC.

        TCPI=(BAC-EV)/(BAC-AC). Undefined ratios and forecasts are null with warnings.
        BAC is the sum of baseline task allocations; period totals must match BAC.
        Expenses after analysis_date are excluded.
        """
        return analyze_evm(payload)

    app.include_router(router)
    return app


app = create_app()
