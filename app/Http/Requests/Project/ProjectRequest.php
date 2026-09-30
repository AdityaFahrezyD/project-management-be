<?php

namespace App\Http\Requests\Project;

use App\Enums\Priority;
use App\Enums\ProjectRole;
use App\Enums\ProjectStatus;
use App\Http\Requests\ApiRequest;
use App\Rules\CurrencyCode;
use Illuminate\Validation\Rule;

class ProjectRequest extends ApiRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');
        if ($project) {
            abort_unless($this->user()->can('view', $project), 404);

            return $this->user()->can(
                in_array($this->route()->getActionMethod(), ['show', 'members', 'summary'], true) ? 'view' : 'update',
                $project,
            );
        }
        $workspace = $this->route('workspace');
        if ($workspace) {
            abort_unless($this->user()->can('view', $workspace), 404);

            return $this->user()->can('update', $workspace);
        }

        return true;
    }

    protected function inputRules(): array
    {
        $action = $this->route()->getActionMethod();
        if (in_array($action, ['index', 'members'], true)) {
            return [...$this->paginationRules(), 'status' => ['sometimes', Rule::enum(ProjectStatus::class)], 'priority' => ['sometimes', Rule::enum(Priority::class)]];
        }
        if ($action === 'status') {
            return ['status' => ['required', Rule::enum(ProjectStatus::class)]];
        }
        if (in_array($action, ['addMember', 'updateMember'], true)) {
            return [
                'user_id' => [$action === 'addMember' ? 'required' : 'prohibited', 'uuid'],
                'role' => ['required', Rule::enum(ProjectRole::class)],
            ];
        }
        if (! in_array($action, ['store', 'update'], true)) {
            return [];
        }
        $required = $action === 'store' ? 'required' : 'sometimes';
        $workspaceId = $this->route('workspace')?->id ?? $this->route('project')?->workspace_id;

        return [
            'name' => [$required, 'required', 'string', 'max:255'],
            'slug' => [$required, 'required', 'string', 'max:255', 'alpha_dash:ascii', Rule::unique('projects')->where('workspace_id', $workspaceId)->ignore($this->route('project')?->id)],
            'description' => ['sometimes', 'nullable', 'string', 'max:16000'],
            'priority' => ['sometimes', Rule::enum(Priority::class)],
            'start_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:1000-01-01', 'before_or_equal:9999-12-31T23:59:59Z'],
            'target_end_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:1000-01-01', 'before_or_equal:9999-12-31T23:59:59Z'],
            'budget_amount' => ['sometimes', 'numeric', 'min:0', 'decimal:0,4', 'regex:/^\\d{1,16}(?:\\.\\d{1,4})?$/D'],
            'currency' => ['sometimes', 'required', new CurrencyCode],
            'status' => ['prohibited'],
            'actual_end_date' => ['prohibited'],
        ];
    }
}
