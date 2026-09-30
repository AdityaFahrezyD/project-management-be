<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Task\TaskRequest;
use App\Http\Resources\StatusHistoryResource;
use App\Http\Resources\TaskResource;
use App\Models\Project;
use App\Models\Task;
use App\Services\TaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class TaskController extends Controller
{
    public function __construct(private TaskService $tasks) {}

    public function index(TaskRequest $request, Project $project): AnonymousResourceCollection
    {
        return TaskResource::collection($this->tasks->list($request->user(), $project, $request->validated()));
    }

    public function store(TaskRequest $request, Project $project): JsonResponse
    {
        return (new TaskResource($this->tasks->create($request->user(), $project, $request->validated())))->response()->setStatusCode(201);
    }

    public function show(TaskRequest $request, Project $project, Task $task): TaskResource
    {
        return new TaskResource($this->tasks->show($task));
    }

    public function update(TaskRequest $request, Project $project, Task $task): TaskResource
    {
        return new TaskResource($this->tasks->update($request->user(), $task, $request->validated()));
    }

    public function status(TaskRequest $request, Project $project, Task $task): TaskResource
    {
        return new TaskResource($this->tasks->status($request->user(), $task, $request->validated('status')));
    }

    public function assign(TaskRequest $request, Project $project, Task $task): TaskResource
    {
        return new TaskResource($this->tasks->assign($request->user(), $task, $request->validated('user_ids')));
    }

    public function archive(TaskRequest $request, Project $project, Task $task): TaskResource
    {
        return new TaskResource($this->tasks->archive($request->user(), $task, true));
    }

    public function restore(TaskRequest $request, Project $project, Task $task): TaskResource
    {
        return new TaskResource($this->tasks->archive($request->user(), $task, false));
    }

    public function destroy(TaskRequest $request, Project $project, Task $task): Response
    {
        $this->tasks->delete($request->user(), $task);

        return response()->noContent();
    }

    public function history(TaskRequest $request, Project $project, Task $task): AnonymousResourceCollection
    {
        return StatusHistoryResource::collection($this->tasks->historyList($task, $request->validated()));
    }
}
