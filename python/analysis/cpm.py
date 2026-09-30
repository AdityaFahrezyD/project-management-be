from decimal import localcontext
from heapq import heapify, heappop, heappush
from uuid import UUID

from analysis.common import ZERO, AnalysisError, rounded
from analysis.schemas import CpmRequest, CpmResponse, CpmResult


def analyze_cpm(payload: CpmRequest) -> CpmResponse:
    """Finish-to-start scheduling in continuous 24-hour days, with no lag."""
    tasks = {task.task_id: task for task in payload.tasks}
    predecessors: dict[UUID, list[UUID]] = {task_id: [] for task_id in tasks}
    successors: dict[UUID, list[UUID]] = {task_id: [] for task_id in tasks}
    for dependency in payload.dependencies:
        predecessors[dependency.successor_task_id].append(dependency.predecessor_task_id)
        successors[dependency.predecessor_task_id].append(dependency.successor_task_id)

    remaining = {task_id: len(incoming) for task_id, incoming in predecessors.items()}
    ready = [task_id for task_id, count in remaining.items() if count == 0]
    heapify(ready)
    order = []
    while ready:
        task_id = heappop(ready)
        order.append(task_id)
        for successor in successors[task_id]:
            remaining[successor] -= 1
            if remaining[successor] == 0:
                heappush(ready, successor)

    if len(order) != len(tasks):
        raise AnalysisError("Task dependencies contain a cycle.")

    with localcontext() as context:
        context.prec = 60
        early_start = {}
        early_finish = {}
        for task_id in order:
            early_start[task_id] = max(
                (early_finish[parent] for parent in predecessors[task_id]), default=ZERO
            )
            early_finish[task_id] = early_start[task_id] + tasks[task_id].duration_days

        duration = max(early_finish.values())
        late_start = {}
        late_finish = {}
        for task_id in reversed(order):
            late_finish[task_id] = min(
                (late_start[child] for child in successors[task_id]), default=duration
            )
            late_start[task_id] = late_finish[task_id] - tasks[task_id].duration_days

        critical = {task_id for task_id in tasks if late_start[task_id] == early_start[task_id]}
        critical_successors = {
            task_id: sorted(
                child
                for child in successors[task_id]
                if child in critical and early_finish[task_id] == early_start[child]
            )
            for task_id in critical
        }
        roots = sorted(task_id for task_id in critical if early_start[task_id] == ZERO)
        paths: list[list[UUID]] = []
        pending = [iter(roots)]
        path: list[UUID] = []
        path_nodes = 0
        truncated = False
        while pending:
            task_id = next(pending[-1], None)
            if task_id is None:
                pending.pop()
                if path:
                    path.pop()
                continue
            path.append(task_id)
            children = critical_successors[task_id]
            if children:
                pending.append(iter(children))
                continue
            if len(paths) >= 1000 or path_nodes + len(path) > 100000:
                truncated = True
                break
            paths.append(path.copy())
            path_nodes += len(path)
            path.pop()

        return CpmResponse(
            project_id=payload.project_id,
            project_duration=rounded(duration),
            critical_paths=paths,
            critical_paths_truncated=truncated,
            results=[
                CpmResult(
                    task_id=task_id,
                    early_start=rounded(early_start[task_id]),
                    early_finish=rounded(early_finish[task_id]),
                    late_start=rounded(late_start[task_id]),
                    late_finish=rounded(late_finish[task_id]),
                    slack=rounded(late_start[task_id] - early_start[task_id]),
                    is_critical=task_id in critical,
                )
                for task_id in sorted(tasks)
            ],
        )
