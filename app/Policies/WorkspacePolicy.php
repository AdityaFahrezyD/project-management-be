<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Workspace;

class WorkspacePolicy
{
    public function role(User $user, Workspace $workspace): ?string
    {
        return $workspace->memberships()->where('user_id', $user->id)->value('role')?->value;
    }

    public function view(User $user, Workspace $workspace): bool
    {
        return $user->is_super_admin || $this->role($user, $workspace) !== null;
    }

    public function update(User $user, Workspace $workspace): bool
    {
        return $user->is_super_admin || in_array($this->role($user, $workspace), ['owner', 'admin'], true);
    }

    public function transfer(User $user, Workspace $workspace): bool
    {
        return $user->is_super_admin || $workspace->owner_id === $user->id;
    }

    public function audit(User $user, Workspace $workspace): bool
    {
        return $this->update($user, $workspace);
    }
}
