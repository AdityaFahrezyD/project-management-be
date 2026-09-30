<?php

namespace App\Policies;

use App\Models\Comment;
use App\Models\User;

class CommentPolicy
{
    public function __construct(private TaskPolicy $tasks) {}

    public function update(User $user, Comment $comment): bool
    {
        return $this->tasks->update($user, $comment->task)
            || ($comment->user_id === $user->id && $this->tasks->collaborate($user, $comment->task));
    }
}
