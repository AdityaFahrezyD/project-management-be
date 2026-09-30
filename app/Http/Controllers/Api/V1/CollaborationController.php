<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Task\CollaborationRequest;
use App\Http\Resources\AttachmentResource;
use App\Http\Resources\CommentResource;
use App\Models\Attachment;
use App\Models\Comment;
use App\Models\Project;
use App\Models\Task;
use App\Services\CollaborationService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CollaborationController extends Controller
{
    public function __construct(private CollaborationService $collaboration) {}

    public function comments(CollaborationRequest $request, Project $project, Task $task): AnonymousResourceCollection
    {
        return CommentResource::collection($this->collaboration->comments($task, $request->validated()));
    }

    public function storeComment(CollaborationRequest $request, Project $project, Task $task): CommentResource
    {
        return new CommentResource($this->collaboration->comment($request->user(), $task, $request->validated()));
    }

    public function updateComment(CollaborationRequest $request, Project $project, Task $task, Comment $comment): CommentResource
    {
        return new CommentResource($this->collaboration->comment($request->user(), $task, $request->validated(), $comment));
    }

    public function destroyComment(CollaborationRequest $request, Project $project, Task $task, Comment $comment): Response
    {
        $this->collaboration->deleteComment($request->user(), $task, $comment);

        return response()->noContent();
    }

    public function attachments(CollaborationRequest $request, Project $project, Task $task): AnonymousResourceCollection
    {
        return AttachmentResource::collection($this->collaboration->attachments($task, $request->validated()));
    }

    public function upload(CollaborationRequest $request, Project $project, Task $task): AttachmentResource
    {
        return new AttachmentResource($this->collaboration->upload($request->user(), $task, $request->validated('file')));
    }

    public function download(CollaborationRequest $request, Project $project, Task $task, Attachment $attachment): StreamedResponse
    {
        return $this->collaboration->download($attachment);
    }

    public function destroyAttachment(CollaborationRequest $request, Project $project, Task $task, Attachment $attachment): Response
    {
        $this->collaboration->deleteAttachment($request->user(), $task, $attachment);

        return response()->noContent();
    }
}
