<?php

namespace App\Http\Requests\Task;

use App\Http\Requests\ApiRequest;
use App\Rules\AttachmentFile;

class CollaborationRequest extends ApiRequest
{
    public function authorize(): bool
    {
        $task = $this->route('task');
        abort_unless($this->user()->can('view', $task), 404);
        $action = $this->route()->getActionMethod();
        if (in_array($action, ['comments', 'attachments', 'download'], true)) {
            return true;
        }
        if ($comment = $this->route('comment')) {
            return $this->user()->can('update', $comment);
        }
        if ($attachment = $this->route('attachment')) {
            return $this->user()->can('delete', $attachment);
        }

        return $this->user()->can('collaborate', $task);
    }

    protected function inputRules(): array
    {
        return match ($this->route()->getActionMethod()) {
            'comments', 'attachments' => $this->paginationRules(),
            'storeComment', 'updateComment' => ['content' => ['required', 'string', 'max:16000']],
            'upload' => ['file' => ['bail', 'required', 'file', 'max:10240', new AttachmentFile]],
            default => [],
        };
    }
}
