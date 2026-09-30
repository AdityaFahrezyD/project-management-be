<?php

namespace App\Http\Requests\Workspace;

use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

class InvitationRequest extends ApiRequest
{
    public function authorize(): bool
    {
        $workspace = $this->route('workspace');
        if (! $workspace) {
            return true;
        }
        abort_unless($this->user()->can('view', $workspace), 404);

        return $this->user()->can('update', $workspace);
    }

    protected function inputRules(): array
    {
        return match ($this->route()->getActionMethod()) {
            'index' => $this->paginationRules(),
            'store' => [
                'email' => ['required', 'email', 'max:255'],
                'role' => ['required', Rule::in(['admin', 'member', 'viewer'])],
            ],
            'accept' => ['token' => ['required', 'string', 'size:64']],
            default => [],
        };
    }
}
