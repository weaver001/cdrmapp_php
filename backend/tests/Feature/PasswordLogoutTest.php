<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class PasswordLogoutTest extends TestCase
{
    use RefreshDatabase;

    private const OLD_PASSWORD = 'SyntheticOldPassword123';

    private const NEW_PASSWORD = 'SyntheticNewPassword456';

    private function passwords(): array
    {
        return ['current_password' => self::OLD_PASSWORD, 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD];
    }

    private function freshAuth(): void
    {
        Auth::forgetGuards();
        Auth::shouldUse('web');
    }

    public function test_native_browser_password_form_redirects_to_login_without_javascript(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'password' => self::OLD_PASSWORD]);
        $this->actingAs($user, 'web')->get('/profile')->assertOk()->assertSee('action="'.route('password.update').'"', false);
        $this->post('/profile/password', $this->passwords())->assertRedirect('/login')
            ->assertSessionHas('status', 'Password updated. Please sign in again.');
        $this->assertGuest('web');
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->fresh()->password));
    }

    public function test_successful_browser_password_changes_log_out_every_role_and_require_login(): void
    {
        foreach (['admin', 'officer', 'citizen'] as $role) {
            $this->freshAuth();
            $user = User::factory()->create(['role' => $role, 'password' => self::OLD_PASSWORD, 'remember_token' => 'synthetic-old-remember-token']);
            $user->createToken('synthetic-mobile');
            $this->actingAs($user, 'web')->withHeader('Origin', config('app.url'))->withSession(['private_marker' => 'must disappear'])
                ->postJson('/api/v1/me/change-password', $this->passwords())->assertOk()
                ->assertJsonPath('data.redirect', '/login')->assertSessionMissing('private_marker');
            $this->assertGuest('web');
            $this->assertSame(0, $user->tokens()->count());
            $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->fresh()->password));
            $this->assertNotSame('synthetic-old-remember-token', $user->fresh()->remember_token);
            $this->freshAuth();
            $this->get('/profile')->assertRedirect('/login');
            $this->get('/login')->assertOk();
        }
    }

    public function test_email_link_password_reset_signs_out_the_browser_for_every_role(): void
    {
        foreach (['admin', 'officer', 'citizen'] as $role) {
            $this->freshAuth();
            $user = User::factory()->create(['role' => $role, 'password' => self::OLD_PASSWORD]);
            $user->createToken('synthetic-mobile');
            $token = Password::createToken($user);
            $this->actingAs($user, 'web')->post('/reset-password', [
                'email' => $user->email, 'token' => $token,
                'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD,
            ])->assertRedirect('/login');
            $this->assertGuest('web');
            $this->assertSame(0, $user->tokens()->count());
            $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->fresh()->password));
            $this->get('/profile')->assertRedirect('/login');
        }
    }

    #[TestWith(['change'])]
    #[TestWith(['reset'])]
    public function test_password_updates_remove_only_the_target_accounts_database_sessions(string $flow): void
    {
        config(['session.driver' => 'database']);
        $user = User::factory()->create(['password' => self::OLD_PASSWORD]);
        $other = User::factory()->create();
        $other->createToken('synthetic-other-account');
        foreach (['synthetic-first-device' => $user->id, 'synthetic-second-device' => $user->id, 'synthetic-other-account' => $other->id] as $id => $userId) {
            DB::table('sessions')->insert(['id' => $id, 'user_id' => $userId, 'payload' => base64_encode(serialize([])), 'last_activity' => now()->timestamp]);
        }
        $this->actingAs($user, 'web')->withHeader('Origin', config('app.url'));
        if ($flow === 'change') {
            $this->postJson('/api/v1/me/change-password', $this->passwords())->assertOk();
        } else {
            $this->post('/reset-password', [
                'email' => $user->email, 'token' => Password::createToken($user),
                'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD,
            ])->assertRedirect('/login');
        }
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertDatabaseHas('sessions', ['id' => 'synthetic-other-account', 'user_id' => $other->id]);
        $this->assertSame(1, $other->tokens()->count());
    }

    public function test_bearer_password_change_revokes_all_tokens_and_old_token_cannot_be_reused(): void
    {
        $user = User::factory()->create(['password' => self::OLD_PASSWORD]);
        $token = $user->createToken('synthetic-phone')->plainTextToken;
        $user->createToken('synthetic-second-phone');
        $this->withToken($token)->postJson('/api/v1/me/change-password', $this->passwords())->assertOk()->assertJsonPath('data.redirect', '/login');
        $this->assertSame(0, $user->tokens()->count());
        $this->freshAuth();
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_invalid_password_change_and_reset_do_not_log_out_or_revoke_tokens(): void
    {
        $user = User::factory()->create(['password' => self::OLD_PASSWORD]);
        $user->createToken('synthetic-phone');
        $this->actingAs($user, 'web')->withHeader('Origin', config('app.url'))
            ->postJson('/api/v1/me/change-password', array_replace($this->passwords(), ['current_password' => 'incorrect']))->assertUnprocessable();
        $this->assertAuthenticatedAs($user, 'web');
        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email, 'token' => 'synthetic-invalid-token',
            'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD,
        ])->assertUnprocessable();
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertSame(1, $user->tokens()->count());
        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $user->fresh()->password));
    }
}
