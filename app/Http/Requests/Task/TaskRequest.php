<?php

namespace App\Http\Requests\Task;

use App\Enums\Priority;
use App\Enums\TaskStatus;
use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

class TaskRequest extends ApiRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');
        abort_unless($this->user()->can('view', $project), 404);
        $task = $this->route('task');
        $action = $this->route()->getActionMethod();
        if (in_array($action, ['index', 'show', 'history'], true)) {
            return true;
        }
        if ($action === 'status') {
            return $this->user()->can('status', $task);
        }

        return $this->user()->can('update', $task ?? $project);
    }

    protected function inputRules(): array
    {
        $action = $this->route()->getActionMethod();
        if (in_array($action, ['index', 'history'], true)) {
            return [
                ...$this->paginationRules(),
                'status' => ['sometimes', Rule::enum(TaskStatus::class)],
                'priority' => ['sometimes', Rule::enum(Priority::class)],
                'parent_id' => ['sometimes', 'nullable', 'uuid'],
                'my_tasks' => ['sometimes', 'boolean'],
                'overdue' => ['sometimes', 'boolean'],
            ];
        }
        if ($action === 'status') {
            return ['status' => ['required', Rule::enum(TaskStatus::class)]];
        }
        if ($action === 'assign') {
            return ['user_ids' => ['present', 'array', 'max:100'], 'user_ids.*' => ['required', 'uuid', 'distinct']];
        }
        if (! in_array($action, ['store', 'update'], true)) {
            return [];
        }

        return [
            'name' => [$action === 'store' ? 'required' : 'sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:16000'],
            'parent_id' => ['sometimes', 'nullable', 'uuid'],
            'priority' => ['sometimes', Rule::enum(Priority::class)],
            'start_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:1000-01-01', 'before_or_equal:9999-12-31T23:59:59Z'],
            'duration_days' => ['sometimes', 'required', 'numeric', 'gt:0', 'decimal:0,4', 'max:99999999.9999'],
            'optimistic_time' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'decimal:0,4', 'max:99999999.9999'],
            'most_likely_time' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'decimal:0,4', 'max:99999999.9999'],
            'pessimistic_time' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'decimal:0,4', 'max:99999999.9999'],
            'due_date' => ['prohibited'],
            'status' => ['prohibited'],
            'completion' => ['prohibited'],
        ];
    }
}
