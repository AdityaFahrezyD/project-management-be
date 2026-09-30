<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Workspace\WorkspaceRequest;
use App\Http\Resources\MembershipResource;
use App\Http\Resources\ProjectResource;
use App\Http\Resources\SummaryResource;
use App\Http\Resources\WorkspaceResource;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\ProjectService;
use App\Services\SummaryService;
use App\Services\WorkspaceService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class WorkspaceController extends Controller
{
    public function __construct(private WorkspaceService $workspaces, private SummaryService $summaries, private ProjectService $projects) {}

    public function index(WorkspaceRequest $request): AnonymousResourceCollection
    {
        return WorkspaceResource::collection($this->workspaces->list($request->user(), $request->validated()));
    }

    public function store(WorkspaceRequest $request): WorkspaceResource
    {
        return new WorkspaceResource($this->workspaces->create($request->user(), $request->validated()));
    }

    public function show(WorkspaceRequest $request, Workspace $workspace): WorkspaceResource
    {
        return new WorkspaceResource($workspace);
    }

    public function update(WorkspaceRequest $request, Workspace $workspace): WorkspaceResource
    {
        return new WorkspaceResource($this->workspaces->update($request->user(), $workspace, $request->validated()));
    }

    public function destroy(WorkspaceRequest $request, Workspace $workspace): Response
    {
        $this->workspaces->delete($request->user(), $workspace);

        return response()->noContent();
    }

    public function archive(WorkspaceRequest $request, Workspace $workspace): WorkspaceResource
    {
        return new WorkspaceResource($this->workspaces->archive($request->user(), $workspace, true));
    }

    public function restore(WorkspaceRequest $request, Workspace $workspace): WorkspaceResource
    {
        return new WorkspaceResource($this->workspaces->archive($request->user(), $workspace, false));
    }

    public function transfer(WorkspaceRequest $request, Workspace $workspace): WorkspaceResource
    {
        return new WorkspaceResource($this->workspaces->transfer($request->user(), $workspace, $request->validated('user_id')));
    }

    public function members(WorkspaceRequest $request, Workspace $workspace): AnonymousResourceCollection
    {
        return MembershipResource::collection($this->workspaces->members($workspace, $request->validated()));
    }

    public function updateMember(WorkspaceRequest $request, Workspace $workspace, WorkspaceMember $membership): Response
    {
        $this->workspaces->changeMember($request->user(), $workspace, $membership, $request->validated('role'));

        return response()->noContent();
    }

    public function removeMember(WorkspaceRequest $request, Workspace $workspace, WorkspaceMember $membership): Response
    {
        $this->workspaces->changeMember($request->user(), $workspace, $membership, null);

        return response()->noContent();
    }

    public function projects(WorkspaceRequest $request, Workspace $workspace): AnonymousResourceCollection
    {
        return ProjectResource::collection($this->projects->list($request->user(), $request->validated(), $workspace));
    }

    public function summary(WorkspaceRequest $request, Workspace $workspace): SummaryResource
    {
        return new SummaryResource($this->summaries->workspace($request->user(), $workspace));
    }
}
