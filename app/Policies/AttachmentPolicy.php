<?php

namespace App\Policies;

use App\Models\Attachment;
use App\Models\User;

class AttachmentPolicy
{
    public function __construct(private TaskPolicy $tasks) {}

    public function view(User $user, Attachment $attachment): bool
    {
        return $this->tasks->view($user, $attachment->task);
    }

    public function delete(User $user, Attachment $attachment): bool
    {
        return $this->tasks->update($user, $attachment->task)
            || ($attachment->user_id === $user->id && $this->tasks->collaborate($user, $attachment->task));
    }
}
