<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Project\ProjectRequest;
use App\Http\Resources\MembershipResource;
use App\Http\Resources\ProjectResource;
use App\Http\Resources\SummaryResource;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Workspace;
use App\Services\ProjectService;
use App\Services\SummaryService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ProjectController extends Controller
{
    public function __construct(private ProjectService $projects, private SummaryService $summaries) {}

    public function index(ProjectRequest $request): AnonymousResourceCollection
    {
        return ProjectResource::collection($this->projects->list($request->user(), $request->validated()));
    }

    public function store(ProjectRequest $request, Workspace $workspace): ProjectResource
    {
        return new ProjectResource($this->projects->create($request->user(), $workspace, $request->validated()));
    }

    public function show(ProjectRequest $request, Project $project): ProjectResource
    {
        return new ProjectResource($project);
    }

    public function update(ProjectRequest $request, Project $project): ProjectResource
    {
        return new ProjectResource($this->projects->update($request->user(), $project, $request->validated()));
    }

    public function status(ProjectRequest $request, Project $project): ProjectResource
    {
        return new ProjectResource($this->projects->status($request->user(), $project, $request->validated('status')));
    }

    public function archive(ProjectRequest $request, Project $project): ProjectResource
    {
        return new ProjectResource($this->projects->archive($request->user(), $project, true));
    }

    public function restore(ProjectRequest $request, Project $project): ProjectResource
    {
        return new ProjectResource($this->projects->archive($request->user(), $project, false));
    }

    public function destroy(ProjectRequest $request, Project $project): Response
    {
        $this->projects->delete($request->user(), $project);

        return response()->noContent();
    }

    public function members(ProjectRequest $request, Project $project): AnonymousResourceCollection
    {
        return MembershipResource::collection($this->projects->members($project, $request->validated()));
    }

    public function addMember(ProjectRequest $request, Project $project): MembershipResource
    {
        return new MembershipResource($this->projects->addMember($request->user(), $project, $request->validated()));
    }

    public function updateMember(ProjectRequest $request, Project $project, ProjectMember $membership): Response
    {
        $this->projects->changeMember($request->user(), $project, $membership, $request->validated('role'));

        return response()->noContent();
    }

    public function removeMember(ProjectRequest $request, Project $project, ProjectMember $membership): Response
    {
        $this->projects->changeMember($request->user(), $project, $membership, null);

        return response()->noContent();
    }

    public function summary(ProjectRequest $request, Project $project): SummaryResource
    {
        return new SummaryResource($this->summaries->project($request->user(), $project));
    }
}
