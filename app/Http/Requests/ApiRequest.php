<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

abstract class ApiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email') && is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    public function rules(): array
    {
        return [
            'id' => ['prohibited'],
            'role' => ['prohibited'],
            'user_id' => ['prohibited'],
            'is_super_admin' => ['prohibited'],
            'is_active' => ['prohibited'],
            'email_verified_at' => ['prohibited'],
            'owner_id' => ['prohibited'],
            'created_by' => ['prohibited'],
            'changed_by' => ['prohibited'],
            'invited_by' => ['prohibited'],
            'approved_by' => ['prohibited'],
            'approved_at' => ['prohibited'],
            'active_baseline_id' => ['prohibited'],
            'archived_at' => ['prohibited'],
            'workspace_id' => ['prohibited'],
            'project_id' => ['prohibited'],
            'task_id' => ['prohibited'],
            ...$this->inputRules(),
        ];
    }

    protected function paginationRules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'search' => ['sometimes', 'string', 'max:150'],
            'include_archived' => ['sometimes', 'boolean'],
        ];
    }

    abstract protected function inputRules(): array;
}
