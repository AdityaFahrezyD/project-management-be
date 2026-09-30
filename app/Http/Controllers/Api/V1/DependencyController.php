<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Task\DependencyRequest;
use App\Http\Resources\DependencyResource;
use App\Models\Project;
use App\Models\TaskDependency;
use App\Services\DependencyService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class DependencyController extends Controller
{
    public function __construct(private DependencyService $dependencies) {}

    public function index(DependencyRequest $request, Project $project): AnonymousResourceCollection
    {
        return DependencyResource::collection($this->dependencies->list($project, $request->validated()));
    }

    public function store(DependencyRequest $request, Project $project): DependencyResource
    {
        return new DependencyResource($this->dependencies->create($request->user(), $project, $request->validated()));
    }

    public function destroy(DependencyRequest $request, Project $project, TaskDependency $dependency): Response
    {
        $this->dependencies->delete($request->user(), $project, $dependency);

        return response()->noContent();
    }
}
