from decimal import Decimal, localcontext

from analysis.common import ZERO, rounded
from analysis.schemas import EvmRequest, EvmResponse, EvmResult

COMPLETION = {
    "todo": Decimal("0"),
    "in_progress": Decimal("0.5"),
    "review": Decimal("0.75"),
    "done": Decimal("1"),
}


def analyze_evm(payload: EvmRequest) -> EvmResponse:
    """Forecast using EAC=BAC/CPI and TCPI=(BAC-EV)/(BAC-AC)."""
    with localcontext() as context:
        context.prec = 60
        budget = sum((task.budget_amount for task in payload.tasks), ZERO)
        earned = sum((task.budget_amount * COMPLETION[task.status] for task in payload.tasks), ZERO)
        actual = sum(
            (
                expense.amount
                for expense in payload.expenses
                if expense.expense_date <= payload.analysis_date
            ),
            ZERO,
        )
        planned = ZERO
        for period in sorted(payload.budget_periods, key=lambda period: period.period_start):
            if payload.analysis_date < period.period_start:
                continue
            period_days = (period.period_end - period.period_start).days + 1
            elapsed_days = (
                min(payload.analysis_date, period.period_end) - period.period_start
            ).days + 1
            planned += period.planned_amount * elapsed_days / period_days

        warnings = []
        cpi = earned / actual if actual else None
        spi = earned / planned if planned else None
        if cpi is None:
            warnings.append("CPI is undefined because actual cost is zero.")
        if spi is None:
            warnings.append("SPI is undefined because planned value is zero.")

        eac = budget / cpi if cpi is not None and cpi > ZERO else None
        if eac is None:
            warnings.append("EAC, ETC and VAC are undefined because CPI is zero or unavailable.")
        tcpi = (budget - earned) / (budget - actual) if budget > actual else None
        if tcpi is None:
            warnings.append("TCPI is undefined because remaining budget is zero or negative.")

        values = {
            "planned_value": planned,
            "earned_value": earned,
            "actual_cost": actual,
            "cost_variance": earned - actual,
            "schedule_variance": earned - planned,
            "cost_performance_index": cpi,
            "schedule_performance_index": spi,
            "estimate_at_completion": eac,
            "estimate_to_complete": eac - actual if eac is not None else None,
            "variance_at_completion": budget - eac if eac is not None else None,
            "to_complete_performance_index": tcpi,
        }
        return EvmResponse(
            project_id=payload.project_id,
            project_baseline_id=payload.project_baseline_id,
            analysis_date=payload.analysis_date,
            currency=payload.currency,
            budget_at_completion=rounded(budget),
            warnings=warnings,
            result=EvmResult(
                **{
                    name: rounded(value) if value is not None else None
                    for name, value in values.items()
                }
            ),
        )
