<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\AuthRequest;
use App\Http\Resources\UserResource;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class AuthController extends Controller
{
    public function __construct(private AuthService $auth) {}

    public function register(AuthRequest $request): UserResource
    {
        return new UserResource($this->auth->register($request->validated(), $request->session()));
    }

    public function login(AuthRequest $request): UserResource
    {
        return new UserResource($this->auth->login($request->validated(), $request->session()));
    }

    public function logout(AuthRequest $request): Response
    {
        $this->auth->logout($request->session());

        return response()->noContent();
    }

    public function me(AuthRequest $request): UserResource
    {
        return new UserResource($request->user());
    }

    public function updateProfile(AuthRequest $request): UserResource
    {
        return new UserResource($this->auth->profile($request->user(), $request->validated()));
    }

    public function changePassword(AuthRequest $request): Response
    {
        $this->auth->changePassword($request->user(), $request->validated(), $request->session());

        return response()->noContent();
    }

    public function forgotPassword(AuthRequest $request): JsonResponse
    {
        $this->auth->forgotPassword($request->validated());

        return response()->json(['message' => 'Jika akun terdaftar, tautan reset password akan dikirim.']);
    }

    public function resetPassword(AuthRequest $request): Response
    {
        $this->auth->resetPassword($request->validated());

        return response()->noContent();
    }

    public function verify(AuthRequest $request, string $id, string $hash): Response
    {
        $this->auth->verify($request->user(), $id, $hash);

        return response()->noContent();
    }

    public function resend(AuthRequest $request): Response
    {
        $this->auth->resend($request->user());

        return response()->noContent();
    }

    public function sessions(AuthRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->auth->sessions($request->user(), $request->session())]);
    }

    public function revokeSession(AuthRequest $request, string $session): Response
    {
        $this->auth->revokeSession($request->user(), $session, $request->session());

        return response()->noContent();
    }
}
