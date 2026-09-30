<?php

namespace App\Http\Requests\Task;

use App\Http\Requests\ApiRequest;

class DependencyRequest extends ApiRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');
        abort_unless($this->user()->can('view', $project), 404);

        return $this->user()->can($this->isMethod('GET') ? 'view' : 'update', $project);
    }

    protected function inputRules(): array
    {
        return match ($this->route()->getActionMethod()) {
            'index' => $this->paginationRules(),
            'store' => [
                'predecessor_task_id' => ['required', 'uuid'],
                'successor_task_id' => ['required', 'uuid', 'different:predecessor_task_id'],
                'dependency_type' => ['sometimes', 'in:FS'],
            ],
            default => [],
        };
    }
}
