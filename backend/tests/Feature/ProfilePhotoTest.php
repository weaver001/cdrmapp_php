<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProfilePhotoTest extends TestCase
{
    use RefreshDatabase;

    private function image(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('profile.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aX1sAAAAASUVORK5CYII='));
    }

    public function test_admin_and_officer_uploads_update_the_avatar_and_replace_photos_on_the_recorded_private_disk(): void
    {
        Storage::fake('local');
        $bucket = Storage::fake('private');
        Storage::fake('public');
        config(['filesystems.profile_disk' => 'private']);
        foreach (['admin', 'officer'] as $role) {
            $old = 'profiles/legacy-'.$role.'.png';
            Storage::disk('local')->put($old, 'legacy image');
            $user = User::factory()->create(['role' => $role, 'profile_photo_path' => $old]);
            $this->actingAs($user);
            Sanctum::actingAs($user);
            $oldUrl = $user->profile_photo_url;
            $this->get('/profile')->assertOk()->assertSee($oldUrl, false);
            $result = $this->postJson('/api/v1/me/photo', ['image' => $this->image()])->assertOk();
            $user->refresh();
            $path = $user->profile_photo_path;
            $this->assertSame('private', $user->profile_photo_disk);
            $bucket->assertExists($path);
            Storage::disk('local')->assertMissing($old);
            Storage::disk('public')->assertMissing($path);
            $result->assertJsonPath('data.photo_url', $user->profile_photo_url);
            $this->assertNotSame($oldUrl, $user->profile_photo_url);
            $this->get('/dashboard')->assertOk()->assertSee($user->profile_photo_url, false)->assertSee('data-profile-avatar', false);
            $profile = $this->get('/profile')->assertOk()->assertSee($user->profile_photo_url, false);
            $this->assertSame(2, substr_count($profile->getContent(), 'data-avatar-image'));
            $photo = $this->get($user->profile_photo_url)->assertOk();
            $this->assertSame($bucket->get($path), $photo->streamedContent());
            $this->assertStringContainsString('no-store', $photo->headers->get('Cache-Control'));
            $this->assertStringContainsString('private', $photo->headers->get('Cache-Control'));
            config(['filesystems.profile_disk' => 'local']);
            $this->get($user->profile_photo_url)->assertOk();
            config(['filesystems.profile_disk' => 'private']);
            $second = $this->postJson('/api/v1/me/photo', ['image' => $this->image()])->assertOk()->json('data.photo_url');
            $this->assertNotSame($result->json('data.photo_url'), $second);
            $bucket->assertMissing($path);
        }
    }

    public function test_profile_photo_is_only_read_from_the_signed_in_account(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create(['profile_photo_path' => 'profiles/owner.png']);
        Storage::disk('local')->put($owner->profile_photo_path, 'private owner image');
        $this->getJson($owner->profile_photo_url)->assertUnauthorized();
        $other = User::factory()->create();
        Sanctum::actingAs($other);
        $this->getJson($owner->profile_photo_url.'&user_id='.$owner->id)->assertNotFound();
        Sanctum::actingAs($owner);
        $this->assertSame('private owner image', $this->get($owner->profile_photo_url)->assertOk()->streamedContent());
        Storage::disk('local')->delete($owner->profile_photo_path);
        $this->getJson($owner->profile_photo_url)->assertNotFound();
    }

    public function test_failed_storage_does_not_replace_the_current_photo(): void
    {
        $user = User::factory()->create(['profile_photo_path' => 'profiles/old.png']);
        Sanctum::actingAs($user);
        config(['filesystems.profile_disk' => 'private']);
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('putFileAs')->once()->andReturn(false);
        Storage::shouldReceive('disk')->with('private')->once()->andReturn($disk);
        $this->postJson('/api/v1/me/photo', ['image' => $this->image()])->assertStatus(503);
        $this->assertSame('profiles/old.png', $user->fresh()->profile_photo_path);
        $this->assertNull($user->fresh()->profile_photo_disk);
    }

    public function test_failed_database_write_removes_the_new_upload_and_keeps_the_old_photo(): void
    {
        Storage::fake('local')->put('profiles/old.png', 'original image');
        $bucket = Storage::fake('private');
        config(['filesystems.profile_disk' => 'private']);
        $user = User::factory()->create(['profile_photo_path' => 'profiles/old.png']);
        Sanctum::actingAs($user);
        $event = 'eloquent.updating: '.User::class;
        Event::listen($event, fn () => throw new \RuntimeException('Synthetic database failure'));
        try {
            $this->postJson('/api/v1/me/photo', ['image' => $this->image()])->assertServerError();
        } finally {
            Event::forget($event);
        }
        $this->assertSame('profiles/old.png', $user->fresh()->profile_photo_path);
        Storage::disk('local')->assertExists('profiles/old.png');
        $this->assertSame([], $bucket->allFiles());
    }
}
