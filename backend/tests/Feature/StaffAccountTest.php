<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class StaffAccountTest extends TestCase
{
    use RefreshDatabase;

    private const TEMPORARY = 'SyntheticTemporary123';

    private const PERSONAL = 'SyntheticPersonal456';

    private function fields(string $role): array
    {
        return ['name' => 'Synthetic '.$role, 'email' => $role.'@cdrmapp.com', 'role' => $role, 'active' => true,
            'agency_id' => $role === 'officer' ? Agency::where('type', 'FIRE')->value('id') : null];
    }

    private function temporary(): array
    {
        return ['initial_password' => self::TEMPORARY, 'initial_password_confirmation' => self::TEMPORARY];
    }

    private function freshAuth(): void
    {
        Auth::forgetGuards();
        Auth::shouldUse('web');
    }

    public function test_admin_creates_staff_with_a_hashed_temporary_password_and_without_any_email(): void
    {
        Notification::fake();
        Sanctum::actingAs(User::factory()->unverified()->create(['role' => 'admin']));
        foreach (['admin', 'officer'] as $role) {
            $response = $this->postJson('/api/v1/management/users', $this->fields($role) + $this->temporary())->assertOk()
                ->assertJsonPath('data.must_change_password', true)->assertJsonMissingPath('data.password')->assertJsonMissingPath('data.initial_password');
            $user = User::findOrFail($response->json('data.id'));
            $this->assertTrue(Hash::check(self::TEMPORARY, $user->password));
            $this->assertNull($user->email_verified_at, 'Staff exemption must not forge email ownership verification.');
            $this->assertTrue($user->hasVerifiedEmail());
            $this->assertStringNotContainsString(self::TEMPORARY, $response->getContent());
            $this->assertStringNotContainsString(self::TEMPORARY, AuditLog::where('record_id', $user->id)->firstOrFail()->toJson());
        }
        Notification::assertNothingSent();
    }

    public function test_new_staff_require_a_confirmed_strong_password_while_citizens_keep_email_invitations(): void
    {
        Notification::fake();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        foreach ([[], ['initial_password' => 'short', 'initial_password_confirmation' => 'short'], ['initial_password' => self::TEMPORARY, 'initial_password_confirmation' => 'Mismatch123456']] as $passwords) {
            $this->postJson('/api/v1/management/users', $this->fields('officer') + $passwords)->assertUnprocessable();
        }
        $this->assertFalse(User::where('email', 'officer@cdrmapp.com')->exists());
        $this->postJson('/api/v1/management/users', $this->fields('citizen') + $this->temporary())->assertUnprocessable();
        $id = $this->postJson('/api/v1/management/users', $this->fields('citizen'))->assertOk()->json('data.id');
        $citizen = User::findOrFail($id);
        $this->assertFalse($citizen->hasVerifiedEmail());
        $this->assertFalse($citizen->must_change_password);
        Notification::assertSentTo($citizen, ResetPassword::class);
    }

    #[TestWith(['admin'])]
    #[TestWith(['officer'])]
    public function test_first_web_login_forces_password_change_then_logs_out_without_email_verification(string $role): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create(['role' => $role, 'password' => self::TEMPORARY, 'must_change_password' => true]);
        $this->withSession(['url.intended' => '/dashboard'])->post('/login', ['email' => $user->email, 'password' => self::TEMPORARY])->assertRedirect(route('password.required'));
        foreach (['/dashboard', '/incident-map', '/manage/users', '/profile'] as $path) {
            $this->get($path)->assertRedirect(route('password.required'));
        }
        $this->get(route('password.required'))->assertOk()->assertSee('Change your temporary password')->assertDontSee('href="/dashboard"', false);
        $this->post('/profile/password', ['current_password' => 'wrong', 'password' => self::PERSONAL, 'password_confirmation' => self::PERSONAL])->assertSessionHasErrors('current_password');
        $this->post('/profile/password', ['current_password' => self::TEMPORARY, 'password' => self::TEMPORARY, 'password_confirmation' => self::TEMPORARY])->assertSessionHasErrors('password');
        $this->assertTrue($user->fresh()->must_change_password);
        $this->post('/profile/password', ['current_password' => self::TEMPORARY, 'password' => self::PERSONAL, 'password_confirmation' => self::PERSONAL])->assertRedirect('/login');
        $this->assertGuest('web');
        $this->assertFalse($user->fresh()->must_change_password);
        $this->assertNull($user->fresh()->email_verified_at);
        $this->freshAuth();
        $this->post('/login', ['email' => $user->email, 'password' => self::PERSONAL])->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertOk();
        Notification::assertNothingSent();
    }

    public function test_api_cannot_bypass_required_password_change_and_password_update_revokes_its_token(): void
    {
        $user = User::factory()->unverified()->create(['role' => 'admin', 'password' => self::TEMPORARY, 'must_change_password' => true]);
        $token = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::TEMPORARY])->assertOk()->json('data.token');
        $this->withToken($token)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.must_change_password', true);
        foreach (['/api/v1/incidents', '/api/v1/reports', '/api/v1/management/users', '/api/v1/notifications', '/api/v1/incident-categories'] as $path) {
            $this->getJson($path)->assertForbidden();
        }
        $this->patchJson('/api/v1/me', ['must_change_password' => false])->assertForbidden();
        $this->postJson('/api/v1/me/change-password', ['current_password' => self::TEMPORARY, 'password' => self::PERSONAL, 'password_confirmation' => self::PERSONAL])->assertOk();
        $this->assertFalse($user->fresh()->must_change_password);
        $this->assertSame(0, $user->tokens()->count());
        $this->freshAuth();
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_admin_can_set_a_new_staff_temporary_password_but_cannot_reset_their_own_this_way(): void
    {
        Notification::fake();
        config(['session.driver' => 'database']);
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->unverified()->create(['role' => 'officer', 'remember_token' => 'synthetic-old-remember-token']);
        $target->createToken('synthetic-old-device');
        foreach (['staff-old-session' => $target->id, 'admin-other-session' => $admin->id] as $id => $userId) {
            DB::table('sessions')->insert(['id' => $id, 'user_id' => $userId, 'payload' => base64_encode(serialize([])), 'last_activity' => now()->timestamp]);
        }
        Sanctum::actingAs($admin);
        $this->patchJson('/api/v1/management/users/'.$target->id, $this->fields('officer') + $this->temporary())->assertOk();
        $this->assertTrue($target->fresh()->must_change_password);
        $this->assertTrue(Hash::check(self::TEMPORARY, $target->fresh()->password));
        $this->assertNotSame('synthetic-old-remember-token', $target->fresh()->remember_token);
        $this->assertSame(0, $target->tokens()->count());
        $this->assertDatabaseMissing('sessions', ['id' => 'staff-old-session']);
        $this->assertDatabaseHas('sessions', ['id' => 'admin-other-session']);
        $this->patchJson('/api/v1/management/users/'.$admin->id, $this->fields('admin') + $this->temporary())->assertUnprocessable();
        Notification::assertNothingSent();
    }

    public function test_password_reset_clears_temporary_password_requirement_and_rejects_reuse(): void
    {
        $user = User::factory()->unverified()->create(['role' => 'officer', 'password' => self::TEMPORARY, 'must_change_password' => true]);
        $token = Password::createToken($user);
        $this->postJson('/api/v1/auth/reset-password', ['email' => $user->email, 'token' => $token, 'password' => self::TEMPORARY, 'password_confirmation' => self::TEMPORARY])->assertUnprocessable();
        $this->assertTrue($user->fresh()->must_change_password);
        $this->postJson('/api/v1/auth/reset-password', ['email' => $user->email, 'token' => $token, 'password' => self::PERSONAL, 'password_confirmation' => self::PERSONAL])->assertOk();
        $this->assertFalse($user->fresh()->must_change_password);
        $this->assertTrue(Hash::check(self::PERSONAL, $user->fresh()->password));
    }

    public function test_custom_domain_does_not_allow_public_registration_to_claim_staff_or_skip_verification(): void
    {
        Notification::fake();
        $id = $this->postJson('/api/v1/auth/register', ['name' => 'Synthetic citizen', 'email' => 'admin@cdrmapp.com', 'password' => self::PERSONAL, 'password_confirmation' => self::PERSONAL, 'role' => 'admin', 'email_verified_at' => now()->toISOString(), 'must_change_password' => false])->assertCreated()->json('data.user.id');
        $user = User::findOrFail($id);
        $this->assertSame('citizen', $user->role);
        $this->assertFalse($user->hasVerifiedEmail());
        Notification::assertSentTo($user, VerifyEmail::class);
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/incidents')->assertForbidden();
        $this->postJson('/api/v1/management/users', $this->fields('officer') + $this->temporary())->assertForbidden();
    }

    public function test_staff_email_changes_and_resend_do_not_send_verification_emails(): void
    {
        Notification::fake();
        foreach (['admin', 'officer', 'citizen'] as $role) {
            $user = User::factory()->unverified()->create(['role' => $role, 'password' => self::PERSONAL]);
            Sanctum::actingAs($user);
            $this->postJson('/api/v1/me/change-email', ['email' => 'new-'.$role.'@cdrmapp.com', 'current_password' => self::PERSONAL])->assertOk();
            if ($role === 'citizen') {
                Notification::assertSentTo($user, VerifyEmail::class);
            } else {
                $this->postJson('/api/v1/auth/verify-email')->assertOk()->assertJsonPath('data.message', 'Email verification is not required for staff accounts.');
                Notification::assertNothingSent();
                $this->assertTrue($user->fresh()->hasVerifiedEmail());
                $this->assertNull($user->fresh()->email_verified_at);
            }
        }
    }
}
