<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StaffResetPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_cannot_reset_a_citizen_password_even_with_confirmation(): void
    {
        $citizen = User::factory()->create();
        $citizen->createToken('existing-device');
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        Password::shouldReceive('sendResetLink')->never();
        $this->postJson('/api/v1/management/users/'.$citizen->id.'/reset-password', ['confirm' => true])->assertForbidden();
        $this->assertFalse($citizen->fresh()->must_change_password);
        $this->assertSame(1, $citizen->tokens()->count());
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_citizen_reset_requests_use_the_android_api_instead_of_staff_web_forgot_password(): void
    {
        Notification::fake();
        $citizen = User::factory()->create();
        $this->post('/forgot-password', ['email' => $citizen->email])->assertRedirect();
        Notification::assertNothingSent();
        $this->postJson('/api/v1/auth/forgot-password', ['email' => $citizen->email])->assertOk();
        Notification::assertSentTo($citizen, ResetPassword::class);
        $this->assertFalse($citizen->fresh()->must_change_password);
    }

    public function test_confirmed_officer_reset_revokes_access_until_password_is_updated(): void
    {
        Notification::fake();
        config(['session.driver' => 'database']);
        $admin = User::factory()->create(['role' => 'admin']);
        $officer = User::factory()->unverified()->create(['role' => 'officer', 'password' => 'SyntheticOriginal123', 'remember_token' => 'synthetic-old-remember']);
        $officer->createToken('old-officer-device');
        DB::table('sessions')->insert(['id' => 'old-officer-session', 'user_id' => $officer->id, 'payload' => base64_encode(serialize([])), 'last_activity' => now()->timestamp]);
        Sanctum::actingAs($admin);
        $url = '/api/v1/management/users/'.$officer->id.'/reset-password';
        $this->postJson($url)->assertUnprocessable();
        $this->postJson($url, ['confirm' => false])->assertUnprocessable();
        Notification::assertNothingSent();
        $this->assertFalse($officer->fresh()->must_change_password);
        $this->postJson($url, ['confirm' => true])->assertOk();
        Notification::assertSentTo($officer, ResetPassword::class);
        $this->assertTrue($officer->fresh()->must_change_password);
        $this->assertSame(0, $officer->tokens()->count());
        $this->assertDatabaseMissing('sessions', ['id' => 'old-officer-session']);
        $this->assertNotSame('synthetic-old-remember', $officer->fresh()->remember_token);
        $log = AuditLog::where('action', 'user.password_reset_requested')->sole();
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertTrue($log->metadata['after']['must_change_password']);
        Auth::forgetGuards();
        Auth::shouldUse('web');
        $this->post('/login', ['email' => $officer->email, 'password' => 'SyntheticOriginal123'])->assertRedirect(route('password.required'));
        $this->get('/dashboard')->assertRedirect(route('password.required'));
        $notification = Notification::sent($officer, ResetPassword::class)->first();
        $this->post('/reset-password', ['email' => $officer->email, 'token' => $notification->token, 'password' => 'SyntheticUpdated456', 'password_confirmation' => 'SyntheticUpdated456'])->assertRedirect('/login');
        $this->assertFalse($officer->fresh()->must_change_password);
        $this->assertTrue(Hash::check('SyntheticUpdated456', $officer->fresh()->password));
        Auth::forgetGuards();
        Auth::shouldUse('web');
        $this->post('/login', ['email' => $officer->email, 'password' => 'SyntheticUpdated456'])->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertOk();
    }

    public function test_confirmed_self_reset_signs_out_the_current_admin_session(): void
    {
        config(['session.driver' => 'database']);
        $admin = User::factory()->create(['role' => 'admin']);
        Password::shouldReceive('sendResetLink')->once()->with(['email' => $admin->email])->andReturn(Password::RESET_LINK_SENT);
        $this->actingAs($admin, 'web')->withHeader('Origin', config('app.url'))
            ->postJson('/api/v1/management/users/'.$admin->id.'/reset-password', ['confirm' => true])->assertOk()->assertJsonPath('data.redirect', '/login');
        $this->assertGuest('web');
        $this->assertTrue($admin->fresh()->must_change_password);
        $this->assertDatabaseMissing('sessions', ['user_id' => $admin->id]);
    }
}
