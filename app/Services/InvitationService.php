<?php

namespace App\Services;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Notifications\WorkspaceInvitationNotification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class InvitationService
{
    public function __construct(private WorkflowService $workflow, private WorkspaceService $workspaces) {}

    public function list(Workspace $workspace, array $filters): LengthAwarePaginator
    {
        return $workspace->invitations()->orderByDesc('created_at')->paginate($filters['per_page'] ?? 20);
    }

    public function create(User $actor, Workspace $workspace, array $data): WorkspaceInvitation
    {
        return DB::transaction(function () use ($actor, $workspace, $data): WorkspaceInvitation {
            $workspace = $this->workflow->workspace($workspace, $actor);
            $this->workspaces->assertRoleGrant($actor, $workspace, $data['role']);
            abort_if($workspace->members()->where('email', $data['email'])->exists(), 409, 'Email sudah menjadi member.');
            abort_if($workspace->invitations()->where('email', $data['email'])->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->exists(), 409, 'Undangan aktif sudah tersedia.');
            $invitation = new WorkspaceInvitation(['workspace_id' => $workspace->id, 'email' => $data['email']]);
            $invitation->role = $data['role'];
            $invitation->invited_by = $actor->id;
            $this->issue($invitation, $workspace);
            $this->workflow->audit($actor, $invitation, 'invitation.created', $workspace->id);

            return $invitation;
        });
    }

    public function resend(User $actor, Workspace $workspace, WorkspaceInvitation $invitation): WorkspaceInvitation
    {
        return DB::transaction(function () use ($actor, $workspace, $invitation): WorkspaceInvitation {
            $workspace = $this->workflow->workspace($workspace, $actor);
            $invitation = $workspace->invitations()->lockForUpdate()->findOrFail($invitation->id);
            $this->workspaces->assertRoleGrant($actor, $workspace, $invitation->role->value);
            abort_if($invitation->accepted_at || $invitation->revoked_at, 409, 'Undangan tidak dapat dikirim ulang.');
            $before = $invitation->getAttributes();
            $invitation->invited_by = $actor->id;
            $this->issue($invitation, $workspace);
            $this->workflow->audit($actor, $invitation, 'invitation.resent', $workspace->id, before: $before);

            return $invitation;
        });
    }

    public function revoke(User $actor, Workspace $workspace, WorkspaceInvitation $invitation): void
    {
        DB::transaction(function () use ($actor, $workspace, $invitation): void {
            $workspace = $this->workflow->workspace($workspace, $actor);
            $invitation = $workspace->invitations()->lockForUpdate()->findOrFail($invitation->id);
            $this->workspaces->assertRoleGrant($actor, $workspace, $invitation->role->value);
            abort_if($invitation->accepted_at, 409, 'Undangan sudah diterima.');
            $invitation->revoked_at = now();
            $invitation->save();
            $this->workflow->audit($actor, $invitation, 'invitation.revoked', $workspace->id);
        });
    }

    public function accept(User $actor, string $token): Workspace
    {
        $candidate = WorkspaceInvitation::query()->where('token_hash', hash('sha256', $token))->firstOrFail();

        return DB::transaction(function () use ($actor, $candidate, $token): Workspace {
            $workspace = Workspace::query()->lockForUpdate()->findOrFail($candidate->workspace_id);
            $invitation = WorkspaceInvitation::query()->lockForUpdate()->findOrFail($candidate->id);
            abort_unless(hash_equals($invitation->token_hash, hash('sha256', $token)), 404);
            abort_if($workspace->archived_at, 409, 'Workspace telah diarsipkan.');
            abort_unless($actor->is_active && $actor->hasVerifiedEmail() && strcasecmp($actor->email, $invitation->email) === 0, 403, 'Email akun tidak sesuai undangan.');
            abort_if($invitation->accepted_at || $invitation->revoked_at || $invitation->expires_at->isPast(), 409, 'Undangan tidak lagi berlaku.');
            abort_if($workspace->memberships()->where('user_id', $actor->id)->exists(), 409, 'Pengguna sudah menjadi member.');
            $workspace->memberships()->forceCreate(['user_id' => $actor->id, 'role' => $invitation->role->value]);
            $invitation->accepted_at = now();
            $invitation->save();
            $this->workflow->audit($actor, $invitation, 'invitation.accepted', $workspace->id);

            return $workspace;
        });
    }

    private function issue(WorkspaceInvitation $invitation, Workspace $workspace): void
    {
        $token = Str::random(64);
        $invitation->token_hash = hash('sha256', $token);
        $invitation->expires_at = now()->addDays(7);
        $invitation->save();
        DB::afterCommit(fn () => Notification::route('mail', $invitation->email)
            ->notify(new WorkspaceInvitationNotification($workspace->name, $token)));
    }
}
