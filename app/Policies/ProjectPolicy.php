<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    public function role(User $user, Project $project): ?string
    {
        if (! $project->workspace->memberships()->where('user_id', $user->id)->exists()) {
            return null;
        }

        return $project->memberships()->where('user_id', $user->id)->value('role')?->value;
    }

    public function view(User $user, Project $project): bool
    {
        return $user->is_super_admin || $this->role($user, $project) !== null;
    }

    public function update(User $user, Project $project): bool
    {
        return $user->is_super_admin || $this->role($user, $project) === 'manager';
    }

    public function audit(User $user, Project $project): bool
    {
        return $this->update($user, $project);
    }
}
