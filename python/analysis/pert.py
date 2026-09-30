from decimal import localcontext

from analysis.common import rounded
from analysis.schemas import PertRequest, PertResponse, PertResult


def analyze_pert(payload: PertRequest) -> PertResponse:
    with localcontext() as context:
        context.prec = 60
        results = []
        for task in sorted(payload.tasks, key=lambda task: task.task_id):
            deviation = (task.pessimistic_time - task.optimistic_time) / 6
            results.append(
                PertResult(
                    task_id=task.task_id,
                    optimistic_time=rounded(task.optimistic_time),
                    most_likely_time=rounded(task.most_likely_time),
                    pessimistic_time=rounded(task.pessimistic_time),
                    expected_time=rounded(
                        (task.optimistic_time + 4 * task.most_likely_time + task.pessimistic_time)
                        / 6
                    ),
                    variance=rounded(deviation**2),
                    standard_deviation=rounded(deviation),
                )
            )
        return PertResponse(project_id=payload.project_id, results=results)
