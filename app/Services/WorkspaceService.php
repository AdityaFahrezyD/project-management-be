<?php

namespace App\Services;

use App\Models\ProjectMember;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class WorkspaceService
{
    public function __construct(private WorkflowService $workflow, private SummaryService $summaries) {}

    public function list(User $actor, array $filters): LengthAwarePaginator
    {
        return Workspace::query()
            ->when(! $actor->is_super_admin, fn ($q) => $q->whereHas('memberships', fn ($q) => $q->where('user_id', $actor->id)))
            ->when(! ($filters['include_archived'] ?? false), fn ($q) => $q->whereNull('archived_at'))
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->where('name', 'like', '%'.$search.'%'))
            ->orderBy('name')->orderBy('id')->paginate($filters['per_page'] ?? 20);
    }

    public function create(User $actor, array $data): Workspace
    {
        return DB::transaction(function () use ($actor, $data): Workspace {
            $workspace = new Workspace($data);
            $workspace->owner_id = $actor->id;
            $workspace->save();
            $workspace->memberships()->forceCreate(['user_id' => $actor->id, 'role' => 'owner']);
            $this->workflow->audit($actor, $workspace, 'workspace.created', $workspace->id);

            return $workspace;
        });
    }

    public function update(User $actor, Workspace $workspace, array $data): Workspace
    {
        return DB::transaction(function () use ($actor, $workspace, $data): Workspace {
            $workspace = $this->workflow->workspace($workspace, $actor);
            $before = $workspace->getAttributes();
            $workspace->update($data);
            $this->workflow->audit($actor, $workspace, 'workspace.updated', $workspace->id, before: $before);

            return $workspace;
        });
    }

    public function archive(User $actor, Workspace $workspace, bool $archived): Workspace
    {
        return DB::transaction(function () use ($actor, $workspace, $archived): Workspace {
            $workspace = $this->workflow->workspace($workspace, $actor, allowArchived: true);
            $before = $workspace->getAttributes();
            $workspace->archived_at = $archived ? now() : null;
            $workspace->save();
            $this->workflow->audit($actor, $workspace, $archived ? 'workspace.archived' : 'workspace.restored', $workspace->id, before: $before);

            return $workspace;
        });
    }

    public function members(Workspace $workspace, array $filters): LengthAwarePaginator
    {
        return $workspace->memberships()->with('user')->orderBy('id')->paginate($filters['per_page'] ?? 20);
    }

    public function changeMember(User $actor, Workspace $workspace, WorkspaceMember $membership, ?string $role): void
    {
        DB::transaction(function () use ($actor, $workspace, $membership, $role): void {
            $workspace = $this->workflow->workspace($workspace, $actor);
            $membership = $workspace->memberships()->findOrFail($membership->id);
            abort_if($membership->user_id === $workspace->owner_id, 409, 'Owner harus dipindahkan melalui transfer kepemilikan.');
            $this->assertRoleGrant($actor, $workspace, $role, $membership->role->value);
            $before = $membership->getAttributes();
            if ($role === null) {
                abort_if(ProjectMember::query()->where('user_id', $membership->user_id)
                    ->whereHas('project', fn ($q) => $q->where('workspace_id', $workspace->id))->exists(), 409, 'Hapus membership project terlebih dahulu.');
                $membership->delete();
            } else {
                $membership->role = $role;
                $membership->save();
            }
            $this->workflow->audit($actor, $membership, $role === null ? 'workspace.member_removed' : 'workspace.member_updated', $workspace->id, before: $before);
        });
    }

    public function assertRoleGrant(User $actor, Workspace $workspace, ?string $role, ?string $existing = null): void
    {
        abort_if($role === 'owner' || $existing === 'owner', 403);
        abort_if(! $actor->is_super_admin && $workspace->owner_id !== $actor->id
            && ($role === 'admin' || $existing === 'admin'), 403, 'Hanya owner yang dapat mengelola admin.');
    }

    public function transfer(User $actor, Workspace $workspace, string $userId): Workspace
    {
        return DB::transaction(function () use ($actor, $workspace, $userId): Workspace {
            $workspace = $this->workflow->workspace($workspace, $actor, 'transfer');
            $target = $workspace->memberships()->where('user_id', $userId)->firstOrFail();
            abort_unless($target->user->is_active && $target->user->hasVerifiedEmail(), 409, 'Owner baru harus aktif dan terverifikasi.');
            abort_if($workspace->owner_id === $userId, 409, 'Pengguna sudah menjadi owner.');
            $before = $workspace->getAttributes();
            $workspace->memberships()->where('user_id', $workspace->owner_id)->update(['role' => 'admin']);
            $target->role = 'owner';
            $target->save();
            $workspace->owner_id = $userId;
            $workspace->save();
            $this->workflow->audit($actor, $workspace, 'workspace.ownership_transferred', $workspace->id, before: $before);

            return $workspace;
        });
    }

    public function delete(User $actor, Workspace $workspace): void
    {
        DB::transaction(function () use ($actor, $workspace): void {
            $workspace = $this->workflow->workspace($workspace, $actor, 'transfer');
            abort_if($workspace->activityLogs()->exists() || $workspace->projects()->exists() || $workspace->invitations()->exists(), 409, 'Workspace memiliki riwayat atau referensi; gunakan arsip.');
            $workspace->members()->detach();
            $workspace->delete();
            $this->summaries->invalidate($workspace->id);
        });
    }
}
