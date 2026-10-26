<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function verificationUrl(User $user, bool $expired = false, ?string $hash = null): string
    {
        return URL::temporarySignedRoute('verification.verify', $expired ? now()->subMinute() : now()->addHour(), [
            'id' => $user->id, 'hash' => $hash ?? sha1($user->getEmailForVerification()),
        ]);
    }

    public function test_browser_link_shows_success_and_login_button_and_verifies_only_once(): void
    {
        Event::fake([Verified::class]);
        $user = User::factory()->unverified()->create();
        $url = $this->verificationUrl($user);
        $this->get($url)->assertOk()->assertViewIs('verification-result')
            ->assertSee('Verified successfully')->assertSee('Return to Login Page')
            ->assertSee('href="'.route('login').'"', false);
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->get($url)->assertOk()->assertViewIs('verification-result');
        Event::assertDispatchedTimes(Verified::class, 1);
    }

    public function test_json_verification_response_remains_available_to_api_clients(): void
    {
        $user = User::factory()->unverified()->create();
        $this->getJson($this->verificationUrl($user))->assertOk()
            ->assertJsonPath('data.message', 'Email verified. You may return to the app.');
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_browser_resend_returns_to_verification_page_with_confirmation(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create(['role' => 'officer']);
        $this->actingAs($user)->post('/email/verification-notification')->assertRedirect(route('verification.notice'))
            ->assertSessionHas('status', 'Verification link sent. Please check your inbox.');
        Notification::assertSentTo($user, VerifyEmail::class);
        $this->get(route('verification.notice'))->assertOk()->assertSee('Verification link sent. Please check your inbox.');
    }

    public function test_api_resend_still_returns_json(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/auth/verify-email')->assertOk()->assertJsonPath('data.message', 'Verification link requested.');
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_invalid_and_expired_links_cannot_verify_an_email(): void
    {
        $user = User::factory()->unverified()->create();
        $this->get($this->verificationUrl($user, expired: true))->assertForbidden();
        $this->get($this->verificationUrl($user, hash: sha1('another@example.test')))->assertForbidden();
        $this->get($this->verificationUrl($user).'tampered')->assertForbidden();
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }
}
