<?php

namespace App\Http\Requests;

class AuditRequest extends ApiRequest
{
    public function authorize(): bool
    {
        $scope = $this->route('project') ?? $this->route('workspace');
        abort_unless($this->user()->can('view', $scope), 404);

        return $this->user()->can('audit', $scope);
    }

    protected function inputRules(): array
    {
        return [
            ...$this->paginationRules(),
            'action' => ['sometimes', 'string', 'max:100'],
            'user_id' => ['sometimes', 'uuid'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
        ];
    }
}
