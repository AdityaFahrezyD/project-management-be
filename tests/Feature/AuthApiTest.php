<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Origin', 'http://localhost:3000');
        Notification::fake();
    }

    public function test_public_registration_normalizes_email_and_requires_verification(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'New User', 'email' => ' NEW@Example.test ', 'password' => 'password123', 'password_confirmation' => 'password123',
        ])->assertCreated()->assertJsonPath('data.email', 'new@example.test')->assertJsonMissingPath('data.password');
        $user = User::findOrFail($response->json('data.id'));
        $this->assertFalse($user->is_super_admin);
        Notification::assertSentTo($user, VerifyEmail::class);
        $this->getJson('/api/v1/workspaces')->assertForbidden();
        $url = URL::temporarySignedRoute('api.v1.auth.verify', now()->addMinutes(60), ['id' => $user->id, 'hash' => sha1($user->email)]);
        $this->getJson($url)->assertNoContent();
        $this->getJson('/api/v1/workspaces')->assertOk();
        $this->postJson('/api/v1/auth/logout')->assertNoContent();
    }

    public function test_verification_cannot_verify_another_account_or_expired_signature(): void
    {
        $user = User::factory()->unverified()->create();
        $other = User::factory()->unverified()->create();
        $this->actingAs($user, 'web');
        $url = URL::temporarySignedRoute('api.v1.auth.verify', now()->addMinutes(60), ['id' => $other->id, 'hash' => sha1($other->email)]);
        $this->getJson($url)->assertForbidden();
        $expired = URL::temporarySignedRoute('api.v1.auth.verify', now()->subMinute(), ['id' => $user->id, 'hash' => sha1($user->email)]);
        $this->getJson($expired)->assertForbidden();
        $this->postJson('/api/v1/auth/email/verification-notification')->assertNoContent();
        $this->postJson('/api/v1/auth/email/verification-notification')->assertTooManyRequests()->assertHeader('Retry-After');
    }

    public function test_login_rejects_inactive_user_and_has_normalized_identity_limit(): void
    {
        User::factory()->create(['email' => 'inactive@example.test', 'is_active' => false]);
        $this->postJson('/api/v1/auth/login', ['email' => 'inactive@example.test', 'password' => 'password'])->assertUnprocessable();
        config(['traffic.login' => 2, 'traffic.login_ip' => 4]);
        $this->postJson('/api/v1/auth/login', ['email' => 'Wrong@example.test', 'password' => 'incorrect'])->assertUnprocessable();
        $this->postJson('/api/v1/auth/login', ['email' => ' wrong@example.test ', 'password' => 'incorrect'])->assertUnprocessable();
        $this->postJson('/api/v1/auth/login', ['email' => 'WRONG@example.test', 'password' => 'incorrect'])->assertTooManyRequests()->assertHeader('Retry-After');
    }

    public function test_password_reset_revokes_sessions_and_tokens_and_does_not_enumerate_accounts(): void
    {
        $user = User::factory()->create();
        DB::table('sessions')->insert(['id' => 'another-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        $user->createToken('old-token');
        $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();
        $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'missing@example.test'])->assertOk();
        $this->assertSame($known->json(), $unknown->json());
        Notification::assertSentTo($user, ResetPassword::class);
        $token = Password::createToken($user);
        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email, 'token' => $token, 'password' => 'new-password', 'password_confirmation' => 'new-password',
        ])->assertNoContent();
        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertSame(0, $user->tokens()->count());
        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email, 'token' => $token, 'password' => 'new-password', 'password_confirmation' => 'new-password',
        ])->assertUnprocessable();
    }

    public function test_session_handles_are_opaque_and_only_revoke_own_sessions(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        DB::table('sessions')->insert([
            ['id' => 'own-secret-session-id', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()],
            ['id' => 'other-secret-session-id', 'user_id' => $other->id, 'payload' => '', 'last_activity' => time()],
        ]);
        $this->actingAs($user, 'web');
        $response = $this->getJson('/api/v1/auth/sessions')->assertOk()->assertJsonCount(1, 'data');
        $handle = $response->json('data.0.id');
        $this->assertNotSame('own-secret-session-id', $handle);
        $this->assertStringNotContainsString('secret-session-id', $response->getContent());
        $foreign = hash_hmac('sha256', 'other-secret-session-id', config('app.key'));
        $this->deleteJson('/api/v1/auth/sessions/'.$foreign)->assertNotFound();
        $this->deleteJson('/api/v1/auth/sessions/'.$handle)->assertNoContent();
        $this->assertDatabaseHas('sessions', ['id' => 'other-secret-session-id']);
    }

    public function test_profile_email_change_revokes_verification_and_password_change_revokes_other_sessions(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'web');
        DB::table('sessions')->insert(['id' => 'other-device', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        $this->putJson('/api/v1/auth/password', ['current_password' => 'wrong', 'password' => 'new-password', 'password_confirmation' => 'new-password'])->assertUnprocessable();
        $this->putJson('/api/v1/auth/password', ['current_password' => 'password', 'password' => 'new-password', 'password_confirmation' => 'new-password'])->assertNoContent();
        $this->assertDatabaseMissing('sessions', ['id' => 'other-device']);
        $this->patchJson('/api/v1/auth/profile', ['email' => 'changed@example.test'])->assertOk()->assertJsonPath('data.email_verified_at', null);
        $this->getJson('/api/v1/projects')->assertForbidden();
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_real_cookie_csrf_login_rotation_and_logout(): void
    {
        config(['session.driver' => 'database']);
        $this->app->bind(ValidateCsrfToken::class, function ($app) {
            return new class($app, $app['encrypter']) extends ValidateCsrfToken
            {
                protected function runningUnitTests(): bool
                {
                    return false;
                }
            };
        });
        $user = User::factory()->create();
        $csrf = $this->getJson('/sanctum/csrf-cookie')->assertNoContent();
        $this->carryCookies($csrf);
        $this->withoutHeader('X-XSRF-TOKEN');
        $oldSession = $csrf->getCookie(config('session.cookie'))->getValue();
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->assertStatus(419);
        $this->withHeader('X-XSRF-TOKEN', $csrf->getCookie('XSRF-TOKEN', false)->getValue());
        $login = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
        $this->assertNotSame($oldSession, $login->getCookie(config('session.cookie'))->getValue());
        $this->assertTrue($login->getCookie(config('session.cookie'))->isHttpOnly());
        $this->carryCookies($login);
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.id', $user->id);
        Auth::forgetGuards();
        $logout = $this->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->carryCookies($logout);
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_cors_allows_configured_origin_with_credentials_and_rejects_foreign_origin(): void
    {
        $this->options('/api/v1/auth/login', [], [
            'Origin' => 'http://localhost:3000', 'Access-Control-Request-Method' => 'POST',
        ])->assertNoContent()->assertHeader('Access-Control-Allow-Origin', 'http://localhost:3000')
            ->assertHeader('Access-Control-Allow-Credentials', 'true');
        $this->options('/api/v1/auth/login', [], [
            'Origin' => 'https://foreign.example', 'Access-Control-Request-Method' => 'POST',
        ])->assertHeader('Access-Control-Allow-Origin', 'http://localhost:3000');
        $this->withHeader('Origin', 'https://foreign.example')->postJson('/api/v1/auth/login', ['email' => 'a@example.test', 'password' => 'password'])->assertStatus(419);
    }

    public function test_inactive_authenticated_user_can_still_logout(): void
    {
        $user = User::factory()->create(['is_active' => false]);
        $this->actingAs($user, 'web')->postJson('/api/v1/auth/logout')->assertNoContent();
    }

    private function carryCookies(TestResponse $response): void
    {
        foreach ($response->headers->getCookies() as $cookie) {
            $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
        }
        if ($token = $response->getCookie('XSRF-TOKEN', false)) {
            $this->withHeader('X-XSRF-TOKEN', $token->getValue());
        }
        Auth::forgetGuards();
    }
}
