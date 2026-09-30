<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthService
{
    public function register(array $data, Session $session): User
    {
        $user = DB::transaction(fn (): User => User::create($data));
        event(new Registered($user));
        Auth::guard('web')->login($user);
        $session->regenerate();

        return $user;
    }

    public function login(array $data, Session $session): User
    {
        if (! Auth::guard('web')->attempt([...$data, 'is_active' => true])) {
            throw ValidationException::withMessages(['email' => 'Email atau password tidak sesuai.']);
        }

        $session->regenerate();

        return Auth::guard('web')->user();
    }

    public function logout(Session $session): void
    {
        Auth::guard('web')->logout();
        $session->invalidate();
        $session->regenerateToken();
    }

    public function profile(User $user, array $data): User
    {
        $emailChanged = isset($data['email']) && $data['email'] !== $user->email;
        $user->fill($data);
        if ($emailChanged) {
            $user->email_verified_at = null;
        }
        $user->save();
        if ($emailChanged) {
            $user->sendEmailVerificationNotification();
        }

        return $user;
    }

    public function changePassword(User $user, array $data, Session $session): void
    {
        abort_unless(Hash::check($data['current_password'], $user->password), 422, 'Password saat ini tidak sesuai.');
        DB::transaction(function () use ($user, $data, $session): void {
            $user->forceFill(['password' => $data['password'], 'remember_token' => Str::random(60)])->save();
            $this->sessionQuery($user)->where('id', '!=', $session->getId())->delete();
            $user->tokens()->delete();
        });
        $session->regenerate();
    }

    public function forgotPassword(array $data): void
    {
        Password::sendResetLink($data);
    }

    public function resetPassword(array $data): void
    {
        $status = Password::reset($data, function (User $user, string $password): void {
            DB::transaction(function () use ($user, $password): void {
                $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
                $this->sessionQuery($user)->delete();
                $user->tokens()->delete();
            });
            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => __($status)]);
        }
    }

    public function verify(User $user, string $id, string $hash): void
    {
        abort_unless(hash_equals($user->id, $id) && hash_equals(sha1($user->getEmailForVerification()), $hash), 403);
        if (! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
            event(new Verified($user));
        }
    }

    public function resend(User $user): void
    {
        if (! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }
    }

    public function sessions(User $user, Session $session): Collection
    {
        return $this->sessionQuery($user)
            ->where('last_activity', '>=', now()->subMinutes(config('session.lifetime'))->timestamp)
            ->orderByDesc('last_activity')->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->map(fn (object $row): array => [
                'id' => $this->sessionHandle($row->id),
                'ip_address' => $row->ip_address,
                'user_agent' => $row->user_agent,
                'last_activity' => $row->last_activity,
                'is_current' => hash_equals($session->getId(), $row->id),
            ]);
    }

    public function revokeSession(User $user, string $handle, Session $session): void
    {
        $id = $this->sessionQuery($user)->pluck('id')->first(fn (string $id): bool => hash_equals($this->sessionHandle($id), $handle));
        abort_if($id === null, 404);
        $this->sessionQuery($user)->where('id', $id)->delete();
        if (hash_equals($session->getId(), $id)) {
            $this->logout($session);
        }
    }

    private function sessionQuery(User $user): Builder
    {
        return DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))->where('user_id', $user->id);
    }

    private function sessionHandle(string $id): string
    {
        return hash_hmac('sha256', $id, config('app.key'));
    }
}
