<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\DeviceToken;
use App\Models\User;
use App\Services\Fcm;
use App\Services\RichText;
use Illuminate\Filesystem\FilesystemAdapter;
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

    private function imageUpload(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('notice.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aX1sAAAAASUVORK5CYII='));
    }

    public function test_cms_upload_uses_persistent_disk_and_published_pages_use_its_url(): void
    {
        Storage::fake('public');
        $bucket = Storage::fake('s3');
        $bucket->buildTemporaryUrlsUsing(fn ($path, $expiration) => 'https://bucket.example.test/'.$path.'?signature=synthetic');
        config(['filesystems.cms_disk' => 's3']);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        Storage::disk('public')->put('content/legacy.png', 'old image');
        $content = Content::create(['type' => 'announcements', 'title' => 'Published notice', 'body' => 'Testing', 'published' => true, 'image_path' => 'content/legacy.png']);

        $response = $this->postJson('/api/v1/management/content/announcements/'.$content->id.'/image', ['image' => $this->imageUpload()])->assertOk();
        $path = $response->json('data.image_path');
        $response->assertJsonPath('data.image_disk', 's3');
        $bucket->assertExists($path);
        Storage::disk('public')->assertMissing('content/legacy.png');
        $content->refresh();
        $this->assertSame('s3', $content->image_disk);
        $url = 'https://bucket.example.test/'.$path.'?signature=synthetic';
        $this->get('/pages/announcements')->assertOk()->assertSee($url, false);
        $this->getJson('/api/v1/public/announcements')->assertOk()->assertJsonPath('data.0.image_url', $url);

        // Changing the configured disk doesn't change previously stored images.
        config(['filesystems.cms_disk' => 'public']);
        $this->assertSame($url, $content->image_url);
        $content->update(['published' => false]);
        $this->get('/pages/announcements')->assertDontSee($url, false);
        $this->getJson('/api/v1/public/announcements')->assertJsonCount(0, 'data');
    }

    public function test_failed_cms_storage_preserves_the_previous_image(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $content = Content::create(['type' => 'announcements', 'title' => 'Notice', 'body' => 'Testing', 'published' => false, 'image_path' => 'content/old.png']);
        config(['filesystems.cms_disk' => 's3']);
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('putFileAs')->once()->andReturn(false);
        Storage::shouldReceive('disk')->with('s3')->once()->andReturn($disk);
        $this->postJson('/api/v1/management/content/announcements/'.$content->id.'/image', ['image' => $this->imageUpload()])->assertStatus(503);
        $this->assertSame('content/old.png', $content->fresh()->image_path);
        $this->assertNull($content->fresh()->image_disk);
    }

    public function test_real_s3_adapter_generates_signed_urls_without_public_acl_or_network_calls(): void
    {
        config(['filesystems.disks.test_bucket' => [
            'driver' => 's3', 'key' => 'synthetic-key', 'secret' => 'synthetic-secret',
            'region' => 'auto', 'bucket' => 'synthetic-bucket',
            'endpoint' => 'https://storage.example.test', 'use_path_style_endpoint' => true,
        ]]);
        $content = new Content(['image_disk' => 'test_bucket', 'image_path' => 'content/notice.png']);
        $this->assertStringStartsWith('https://storage.example.test/synthetic-bucket/content/notice.png?', $content->image_url);
        parse_str(parse_url($content->image_url, PHP_URL_QUERY), $query);
        $this->assertSame('1800', $query['X-Amz-Expires']);
        $this->assertNotEmpty($query['X-Amz-Signature']);
        $this->assertArrayNotHasKey('x-amz-acl', $query);
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
        config(['services.fcm.enabled' => true, 'services.fcm.project' => 'synthetic-project', 'services.fcm.credentials' => $path, 'services.fcm.credentials_json' => null]);
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 'synthetic-access'], 200), 'fcm.googleapis.com/*' => Http::response(['name' => 'synthetic-message'], 200)]);
        app(Fcm::class)->send($a, ['incident_id' => 123, 'status' => 'received']);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'fcm.googleapis.com') && $r['message']['token'] === 'synthetic-a' && ! isset($r['message']['data']['description']));
        Http::assertNotSent(fn ($r) => isset($r['message']['token']) && $r['message']['token'] === 'synthetic-b');
        unlink($path);
    }
}
