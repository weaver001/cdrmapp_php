<?php

namespace Tests\Feature;

use App\Models\DeviceToken;
use App\Models\User;
use App\Services\Fcm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FcmSecretTest extends TestCase
{
    use RefreshDatabase;

    private function account(): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($key, $private);

        return ['client_email' => 'synthetic@example.test', 'private_key' => $private];
    }

    private function configure(mixed $json): User
    {
        config(['services.fcm.enabled' => true, 'services.fcm.project' => 'synthetic-project', 'services.fcm.credentials' => '/nonexistent/unused.json', 'services.fcm.credentials_json' => $json]);
        Http::preventStrayRequests();
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 'synthetic-access']), 'fcm.googleapis.com/*' => Http::response(['name' => 'synthetic-message'])]);
        $user = User::factory()->create();
        DeviceToken::create(['user_id' => $user->id, 'token' => 'synthetic-device']);

        return $user;
    }

    public function test_json_secret_signs_valid_jwt_without_a_file_and_rotation_refreshes_access_token(): void
    {
        $account = $this->account();
        $user = $this->configure(json_encode($account));
        $fcm = app(Fcm::class);
        $fcm->send($user, ['status' => 'done']);
        Http::assertSent(function ($request) use ($account) {
            if ($request->url() !== 'https://oauth2.googleapis.com/token') {
                return false;
            }
            [$header, $payload, $signature] = explode('.', $request['assertion']);
            $decode = fn ($value) => base64_decode(strtr($value, '-_', '+/'));
            $key = openssl_pkey_get_private($account['private_key']);
            $public = openssl_pkey_get_details($key)['key'];
            $claims = json_decode($decode($payload), true);

            return openssl_verify($header.'.'.$payload, $decode($signature), $public, OPENSSL_ALGO_SHA256) === 1
                && $claims['iss'] === $account['client_email']
                && $claims['aud'] === 'https://oauth2.googleapis.com/token';
        });
        $fcm->send($user, ['status' => 'need_action']);
        $this->assertCount(1, Http::recorded(fn ($r) => str_contains($r->url(), 'oauth2.googleapis.com')));
        config(['services.fcm.credentials_json' => json_encode($this->account())]);
        $fcm->send($user, ['status' => 'done']);
        $this->assertCount(2, Http::recorded(fn ($r) => str_contains($r->url(), 'oauth2.googleapis.com')));
        Http::assertSent(fn ($r) => $r->url() === 'https://fcm.googleapis.com/v1/projects/synthetic-project/messages:send'
            && $r['message']['token'] === 'synthetic-device'
            && $r['message']['data']['status'] === 'done'
            && $r['message']['notification']['title'] === 'CDRMAPP status update'
            && $r['message']['android']['priority'] === 'high'
            && $r['message']['android']['notification']['channel_id'] === 'incident-status'
            && $r['message']['android']['notification']['visibility'] === 'PRIVATE'
            && ! isset($r['message']['notification']['description']));
    }

    public function test_invalid_json_and_credentials_fail_safely_without_network_requests(): void
    {
        $user = $this->configure('');
        foreach (['sensitive-invalid-json', 'null', '{}', json_encode(['client_email' => 'synthetic@example.test', 'private_key' => 'sensitive-invalid-key'])] as $json) {
            config(['services.fcm.credentials_json' => $json]);
            try {
                app(Fcm::class)->send($user, []);
                $this->fail('Invalid credentials must be rejected.');
            } catch (\RuntimeException $e) {
                $this->assertStringStartsWith('FCM service-account', $e->getMessage());
                $this->assertStringNotContainsString('sensitive-', $e->getMessage());
            }
        }
        Http::assertNothingSent();
    }

    public function test_disabled_fcm_does_not_parse_secret_or_contact_google(): void
    {
        $user = $this->configure('invalid');
        config(['services.fcm.enabled' => false]);
        app(Fcm::class)->send($user, []);
        Http::assertNothingSent();
    }

    public function test_recipients_without_registered_devices_do_not_contact_google(): void
    {
        $user = $this->configure('invalid');
        DeviceToken::where('user_id', $user->id)->delete();
        app(Fcm::class)->send($user, ['status' => 'done']);
        Http::assertNothingSent();
    }
}
