<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\Comment;
use App\Models\Task;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class CollaborationService
{
    public function __construct(private WorkflowService $workflow) {}

    public function comments(Task $task, array $filters): LengthAwarePaginator
    {
        return $task->comments()->with('user')->orderBy('created_at')->orderBy('id')->paginate($filters['per_page'] ?? 20);
    }

    public function comment(User $actor, Task $task, array $data, ?Comment $comment = null): Comment
    {
        return DB::transaction(function () use ($actor, $task, $data, $comment): Comment {
            $task = $this->workflow->task($task, $actor, 'collaborate');
            $before = $comment?->getAttributes();
            if ($comment) {
                $comment = $task->comments()->findOrFail($comment->id);
                Gate::forUser($actor)->authorize('update', $comment);
                $comment->fill($data);
            } else {
                $comment = new Comment([...$data, 'task_id' => $task->id]);
                $comment->user_id = $actor->id;
            }
            $comment->save();
            $this->workflow->audit($actor, $comment, $before === null ? 'comment.created' : 'comment.updated', $task->project->workspace_id, $task->project_id, $before);

            return $comment->load('user');
        });
    }

    public function deleteComment(User $actor, Task $task, Comment $comment): void
    {
        DB::transaction(function () use ($actor, $task, $comment): void {
            $task = $this->workflow->task($task, $actor, 'collaborate');
            $comment = $task->comments()->findOrFail($comment->id);
            Gate::forUser($actor)->authorize('update', $comment);
            $before = $comment->getAttributes();
            $comment->delete();
            $this->workflow->audit($actor, $comment, 'comment.deleted', $task->project->workspace_id, $task->project_id, $before);
        });
    }

    public function attachments(Task $task, array $filters): LengthAwarePaginator
    {
        return $task->attachments()->orderByDesc('created_at')->orderBy('id')->paginate($filters['per_page'] ?? 20);
    }

    public function upload(User $actor, Task $task, UploadedFile $file): Attachment
    {
        $path = null;
        try {
            return DB::transaction(function () use ($actor, $task, $file, &$path): Attachment {
                $task = $this->workflow->task($task, $actor, 'collaborate');
                $path = $file->store('attachments/'.$task->id, 'local');
                if ($path === false) {
                    throw new RuntimeException('Attachment tidak dapat disimpan.');
                }
                $attachment = new Attachment([
                    'task_id' => $task->id,
                    'file_name' => basename(str_replace('\\', '/', $file->getClientOriginalName())),
                    'file_path' => $path,
                    'file_type' => $file->getMimeType(),
                    'file_size' => $file->getSize(),
                ]);
                $attachment->user_id = $actor->id;
                $attachment->save();
                $this->workflow->audit($actor, $attachment, 'attachment.created', $task->project->workspace_id, $task->project_id);

                return $attachment;
            });
        } catch (Throwable $exception) {
            if ($path && ! Attachment::query()->where('file_path', $path)->exists()) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }
    }

    public function download(Attachment $attachment): StreamedResponse
    {
        abort_unless(Storage::disk('local')->exists($attachment->file_path), 404);

        return Storage::disk('local')->download($attachment->file_path, $attachment->file_name, [
            'Content-Type' => 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function deleteAttachment(User $actor, Task $task, Attachment $attachment): void
    {
        DB::transaction(function () use ($actor, $task, $attachment): void {
            $task = $this->workflow->task($task, $actor, 'collaborate');
            $attachment = $task->attachments()->findOrFail($attachment->id);
            Gate::forUser($actor)->authorize('delete', $attachment);
            $before = $attachment->getAttributes();
            $path = $attachment->file_path;
            $attachment->delete();
            $this->workflow->audit($actor, $attachment, 'attachment.deleted', $task->project->workspace_id, $task->project_id, $before);
            DB::afterCommit(fn () => Storage::disk('local')->delete($path));
        });
    }
}
