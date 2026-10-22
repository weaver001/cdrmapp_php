<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\DeviceToken;
use App\Models\User;
use App\Services\Fcm;
use App\Services\RichText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MediaAndSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_rich_text_keeps_formatting_and_removes_active_content(): void
    {
        $html = app(RichText::class)->sanitize('<p onclick="alert(1)"><strong>Notice</strong><script>alert(1)</script><a href="javascript:alert(1)">link</a><img src=x onerror=alert(1)></p>');
        $this->assertStringContainsString('<strong>Notice</strong>', $html);
        foreach (['onclick', '<script', 'javascript:', '<img', 'onerror'] as $bad) {
            $this->assertStringNotContainsString($bad, $html);
        }
    }

    public function test_cms_images_require_admin_and_reject_executable_uploads(): void
    {
        Storage::fake('public');
        $u = User::factory()->create();
        Sanctum::actingAs($u);
        $c = Content::create(['type' => 'announcements', 'title' => 'Synthetic', 'body' => 'Testing', 'published' => false]);
        $this->postJson('/api/v1/management/content/announcements/'.$c->id.'/image', ['image' => UploadedFile::fake()->create('script.php', 10, 'application/x-httpd-php')])->assertForbidden();
        $u->forceFill(['role' => 'admin'])->save();
        $this->postJson('/api/v1/management/content/announcements/'.$c->id.'/image', ['image' => UploadedFile::fake()->create('script.php', 10, 'application/x-httpd-php')])->assertUnprocessable();
    }

    public function test_fcm_targets_only_the_notified_user_and_uses_generic_status_data(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        DeviceToken::create(['user_id' => $a->id, 'token' => 'synthetic-a']);
        DeviceToken::create(['user_id' => $b->id, 'token' => 'synthetic-b']);
        Storage::fake('local');
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($key, $private);
        $path = Storage::disk('local')->path('synthetic-service-account.json');
        file_put_contents($path, json_encode(['client_email' => 'synthetic@example.test', 'private_key' => $private]));
        config(['services.fcm.enabled' => true, 'services.fcm.project' => 'synthetic-project', 'services.fcm.credentials' => $path]);
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 'synthetic-access'], 200), 'fcm.googleapis.com/*' => Http::response(['name' => 'synthetic-message'], 200)]);
        app(Fcm::class)->send($a, ['incident_id' => 123, 'status' => 'received']);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'fcm.googleapis.com') && $r['message']['token'] === 'synthetic-a' && ! isset($r['message']['data']['description']));
        Http::assertNotSent(fn ($r) => isset($r['message']['token']) && $r['message']['token'] === 'synthetic-b');
        unlink($path);
    }
}
