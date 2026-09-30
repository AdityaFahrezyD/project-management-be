<?php

namespace App\Providers;

use App\Models\PersonalAccessToken;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        RateLimiter::for('login', function (Request $request): array {
            $email = $request->input('email');
            $identity = hash('sha256', is_string($email) ? mb_strtolower(trim($email)) : 'invalid');

            return [
                Limit::perMinute(config('traffic.login'))->by('login:'.$identity.':'.$request->ip()),
                Limit::perMinute(config('traffic.login_ip'))->by('login-ip:'.$request->ip()),
            ];
        });
        RateLimiter::for('api', function (Request $request): Limit {
            $rateLimitGroup = $request->isMethodSafe() ? 'read' : 'write';

            return Limit::perMinute(config('traffic.'.$rateLimitGroup))->by($rateLimitGroup.':'.$request->user()->id);
        });
        RateLimiter::for('recovery', fn (Request $request): Limit => Limit::perMinute(config('traffic.recovery'))
            ->by('recovery:'.$request->route()->getName().':'.$request->ip()));
        RateLimiter::for('verification', fn (Request $request): Limit => Limit::perMinute(config('traffic.verification'))
            ->by('verification:'.$request->user()->id));

        ResetPassword::createUrlUsing(fn (object $user, string $token): string => rtrim(config('frontend.url'), '/')
            .'/reset-password?'.http_build_query(['token' => $token, 'email' => $user->getEmailForPasswordReset()]));
        VerifyEmail::createUrlUsing(function (object $user): string {
            $url = URL::temporarySignedRoute('api.v1.auth.verify', now()->addMinutes(60), [
                'id' => $user->getKey(),
                'hash' => sha1($user->getEmailForVerification()),
            ]);

            return rtrim(config('frontend.url'), '/').'/verify-email?'.http_build_query(['url' => $url]);
        });
    }
}
