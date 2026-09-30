from datetime import date
from decimal import Decimal
from typing import Annotated, Literal, Self
from uuid import UUID

from pydantic import BaseModel, ConfigDict, Field, PlainSerializer, model_validator

from analysis import ENGINE_VERSION

Duration = Annotated[Decimal, Field(gt=0, max_digits=12, decimal_places=4, allow_inf_nan=False)]
Money = Annotated[Decimal, Field(ge=0, max_digits=20, decimal_places=4, allow_inf_nan=False)]
ResultNumber = Annotated[
    Decimal,
    Field(allow_inf_nan=False, max_digits=24, decimal_places=8),
    PlainSerializer(lambda value: format(value, ".8f"), return_type=str, when_used="json"),
]
TaskStatus = Literal["todo", "in_progress", "review", "done"]


class Payload(BaseModel):
    model_config = ConfigDict(extra="forbid")


class AnalysisRequest(Payload):
    project_id: UUID


class AnalysisResponse(Payload):
    project_id: UUID
    engine_version: str = ENGINE_VERSION


class PertTask(Payload):
    task_id: UUID
    optimistic_time: Duration
    most_likely_time: Duration
    pessimistic_time: Duration

    @model_validator(mode="after")
    def validate_estimates(self) -> Self:
        if not self.optimistic_time <= self.most_likely_time <= self.pessimistic_time:
            raise ValueError("Estimates must satisfy optimistic <= most likely <= pessimistic.")
        return self


class PertRequest(AnalysisRequest):
    tasks: list[PertTask] = Field(min_length=1, max_length=10000)

    @model_validator(mode="after")
    def validate_tasks(self) -> Self:
        if len({task.task_id for task in self.tasks}) != len(self.tasks):
            raise ValueError("Task IDs must be unique.")
        return self


class PertResult(Payload):
    task_id: UUID
    optimistic_time: ResultNumber
    most_likely_time: ResultNumber
    pessimistic_time: ResultNumber
    expected_time: ResultNumber
    variance: ResultNumber
    standard_deviation: ResultNumber


class PertResponse(AnalysisResponse):
    results: list[PertResult]


class CpmTask(Payload):
    task_id: UUID
    duration_days: Duration = Field(description="PM planned duration; not PERT expected time.")


class Dependency(Payload):
    predecessor_task_id: UUID
    successor_task_id: UUID


class CpmRequest(AnalysisRequest):
    tasks: list[CpmTask] = Field(min_length=1, max_length=10000)
    dependencies: list[Dependency] = Field(default_factory=list, max_length=50000)

    @model_validator(mode="after")
    def validate_graph_references(self) -> Self:
        task_ids = {task.task_id for task in self.tasks}
        if len(task_ids) != len(self.tasks):
            raise ValueError("Task IDs must be unique.")

        edges: set[tuple[UUID, UUID]] = set()
        for dependency in self.dependencies:
            predecessor = dependency.predecessor_task_id
            successor = dependency.successor_task_id
            if predecessor not in task_ids or successor not in task_ids:
                raise ValueError("Every dependency must reference a task in this request.")
            if predecessor == successor:
                raise ValueError("A task cannot depend on itself.")
            if (predecessor, successor) in edges:
                raise ValueError("Dependencies must be unique.")
            edges.add((predecessor, successor))
        return self


class CpmResult(Payload):
    task_id: UUID
    early_start: ResultNumber
    early_finish: ResultNumber
    late_start: ResultNumber
    late_finish: ResultNumber
    slack: ResultNumber
    is_critical: bool


class CpmResponse(AnalysisResponse):
    project_duration: ResultNumber
    critical_paths: list[list[UUID]]
    critical_paths_truncated: bool = Field(
        description="True if enumeration exceeded 1000 paths or 100000 total path nodes. "
        "Task results and project duration are still complete."
    )
    results: list[CpmResult]


class EvmTask(Payload):
    task_id: UUID
    budget_amount: Money = Field(description="Approved baseline allocation for this leaf task.")
    status: TaskStatus = Field(description="Task status as of analysis_date, supplied by Laravel.")


class BudgetPeriod(Payload):
    period_start: date
    period_end: date
    planned_amount: Money

    @model_validator(mode="after")
    def validate_dates(self) -> Self:
        if self.period_end < self.period_start:
            raise ValueError("period_end must be on or after period_start.")
        return self


class Expense(Payload):
    expense_id: UUID
    expense_date: date
    amount: Money = Field(gt=0)
    currency: str = Field(pattern=r"^[A-Z]{3}$")


class EvmRequest(AnalysisRequest):
    project_baseline_id: UUID
    analysis_date: date
    currency: str = Field(pattern=r"^[A-Z]{3}$")
    tasks: list[EvmTask] = Field(min_length=1, max_length=10000)
    budget_periods: list[BudgetPeriod] = Field(
        min_length=1,
        max_length=10000,
        description="Project-level time-phased approved baseline. Inclusive calendar days; "
        "PV is prorated daily through the end of analysis_date.",
    )
    expenses: list[Expense] = Field(default_factory=list, max_length=50000)

    @model_validator(mode="after")
    def validate_baseline(self) -> Self:
        if len({task.task_id for task in self.tasks}) != len(self.tasks):
            raise ValueError("Task IDs must be unique; send leaf tasks without parent summaries.")
        if len({expense.expense_id for expense in self.expenses}) != len(self.expenses):
            raise ValueError("Expense IDs must be unique.")
        if any(expense.currency != self.currency for expense in self.expenses):
            raise ValueError("Every expense must use the baseline currency.")

        periods = sorted(self.budget_periods, key=lambda period: period.period_start)
        for previous, current in zip(periods, periods[1:], strict=False):
            if current.period_start <= previous.period_end:
                raise ValueError("Project-level budget periods must not overlap.")

        task_total = sum((task.budget_amount for task in self.tasks), Decimal(0))
        period_total = sum((period.planned_amount for period in periods), Decimal(0))
        if task_total != period_total:
            raise ValueError(
                "Budget period total must equal total baseline task allocations (BAC)."
            )
        return self


class EvmResult(Payload):
    planned_value: ResultNumber
    earned_value: ResultNumber
    actual_cost: ResultNumber
    cost_variance: ResultNumber
    schedule_variance: ResultNumber
    cost_performance_index: ResultNumber | None
    schedule_performance_index: ResultNumber | None
    estimate_at_completion: ResultNumber | None
    estimate_to_complete: ResultNumber | None
    variance_at_completion: ResultNumber | None
    to_complete_performance_index: ResultNumber | None


class EvmResponse(AnalysisResponse):
    project_baseline_id: UUID
    analysis_date: date
    currency: str
    budget_at_completion: ResultNumber = Field(
        description="Sum of approved baseline leaf-task allocations, excluding unallocated reserve."
    )
    warnings: list[str]
    result: EvmResult
