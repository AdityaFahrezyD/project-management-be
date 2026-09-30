<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class AuthRequest extends ApiRequest
{
    protected function inputRules(): array
    {
        $email = ['required', 'string', 'email', 'max:255'];

        return match ($this->route()->getActionMethod()) {
            'register' => [
                'name' => ['required', 'string', 'max:255'],
                'email' => [...$email, Rule::unique('users')],
                'password' => ['required', 'confirmed', Password::min(8)],
            ],
            'login' => ['email' => $email, 'password' => ['required', 'string']],
            'updateProfile' => [
                'name' => ['sometimes', 'required', 'string', 'max:255'],
                'email' => ['sometimes', ...$email, Rule::unique('users')->ignore($this->user()->id)],
                'avatar' => ['sometimes', 'nullable', 'url:http,https', 'max:2048'],
            ],
            'changePassword' => [
                'current_password' => ['required', 'string'],
                'password' => ['required', 'confirmed', Password::min(8)],
            ],
            'forgotPassword' => ['email' => $email],
            'resetPassword' => [
                'email' => $email,
                'token' => ['required', 'string'],
                'password' => ['required', 'confirmed', Password::min(8)],
            ],
            default => [],
        };
    }
}
