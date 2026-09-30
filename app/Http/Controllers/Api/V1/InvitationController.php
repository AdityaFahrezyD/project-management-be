<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Workspace\InvitationRequest;
use App\Http\Resources\InvitationResource;
use App\Http\Resources\WorkspaceResource;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Services\InvitationService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class InvitationController extends Controller
{
    public function __construct(private InvitationService $invitations) {}

    public function index(InvitationRequest $request, Workspace $workspace): AnonymousResourceCollection
    {
        return InvitationResource::collection($this->invitations->list($workspace, $request->validated()));
    }

    public function store(InvitationRequest $request, Workspace $workspace): InvitationResource
    {
        return new InvitationResource($this->invitations->create($request->user(), $workspace, $request->validated()));
    }

    public function resend(InvitationRequest $request, Workspace $workspace, WorkspaceInvitation $invitation): InvitationResource
    {
        return new InvitationResource($this->invitations->resend($request->user(), $workspace, $invitation));
    }

    public function revoke(InvitationRequest $request, Workspace $workspace, WorkspaceInvitation $invitation): Response
    {
        $this->invitations->revoke($request->user(), $workspace, $invitation);

        return response()->noContent();
    }

    public function accept(InvitationRequest $request): WorkspaceResource
    {
        return new WorkspaceResource($this->invitations->accept($request->user(), $request->validated('token')));
    }
}
