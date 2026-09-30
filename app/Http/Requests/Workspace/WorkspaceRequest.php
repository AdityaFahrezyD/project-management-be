<?php

namespace App\Http\Requests\Workspace;

use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

class WorkspaceRequest extends ApiRequest
{
    public function authorize(): bool
    {
        $workspace = $this->route('workspace');
        if (! $workspace) {
            return true;
        }
        abort_unless($this->user()->can('view', $workspace), 404);
        $ability = match ($this->route()->getActionMethod()) {
            'show', 'members', 'summary', 'projects' => 'view',
            'transfer', 'destroy' => 'transfer',
            default => 'update',
        };

        return $this->user()->can($ability, $workspace);
    }

    protected function inputRules(): array
    {
        $action = $this->route()->getActionMethod();
        if (in_array($action, ['index', 'members', 'projects'], true)) {
            return $this->paginationRules();
        }
        if ($action === 'transfer') {
            return ['user_id' => ['required', 'uuid']];
        }
        if ($action === 'updateMember') {
            return ['role' => ['required', Rule::in(['admin', 'member', 'viewer'])]];
        }
        if (! in_array($action, ['store', 'update'], true)) {
            return [];
        }
        $required = $action === 'store' ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'required', 'string', 'max:255'],
            'slug' => [$required, 'required', 'string', 'max:255', 'alpha_dash:ascii', Rule::unique('workspaces')->ignore($this->route('workspace')?->id)],
            'description' => ['sometimes', 'nullable', 'string', 'max:16000'],
            'settings' => ['sometimes', 'nullable', 'array'],
            'role' => ['prohibited'],
        ];
    }
}
