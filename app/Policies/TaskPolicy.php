<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;

class TaskPolicy
{
    public function __construct(private ProjectPolicy $projects) {}

    public function view(User $user, Task $task): bool
    {
        return $this->projects->view($user, $task->project);
    }

    public function update(User $user, Task $task): bool
    {
        return $this->projects->update($user, $task->project);
    }

    public function collaborate(User $user, Task $task): bool
    {
        return $this->update($user, $task)
            || ($this->projects->role($user, $task->project) === 'member'
                && $task->assignments()->where('user_id', $user->id)->exists());
    }

    public function status(User $user, Task $task): bool
    {
        return $this->collaborate($user, $task);
    }
}
