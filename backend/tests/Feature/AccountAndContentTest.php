<?php

namespace Tests\Feature;

use App\Models\ContactMessage;
use App\Models\Content;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccountAndContentTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_never_accepts_role_and_requires_email_verification(): void
    {
        Notification::fake();
        $this->postJson('/api/v1/auth/register', ['name' => 'Synthetic Citizen', 'email' => 'citizen@example.test', 'password' => 'SecurePassword123', 'password_confirmation' => 'SecurePassword123', 'role' => 'admin'])->assertCreated();
        $u = User::first();
        $this->assertSame('citizen', $u->role);
        $this->assertNull($u->email_verified_at);
        Notification::assertSentTo($u, VerifyEmail::class);
        $t = $this->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => 'SecurePassword123'])->assertOk()->json('data.token');
        $this->withToken($t)->getJson('/api/v1/me')->assertOk();
        $this->withToken($t)->getJson('/api/v1/incidents')->assertForbidden();
    }

    public function test_inactive_user_cannot_reuse_existing_token(): void
    {
        $u = User::factory()->create();
        $token = $u->createToken('test')->plainTextToken;
        $u->forceFill(['active' => false])->save();
        $this->withToken($token)->getJson('/api/v1/me')->assertForbidden();
    }

    public function test_cms_publication_and_admin_boundaries(): void
    {
        $u = User::factory()->create();
        Sanctum::actingAs($u);
        $d = ['title' => 'Synthetic announcement', 'body' => '<script>alert(1)</script>Safe text', 'published' => false];
        $this->postJson('/api/v1/management/content/announcements', $d)->assertForbidden();
        $u->forceFill(['role' => 'admin'])->save();
        $id = $this->postJson('/api/v1/management/content/announcements', $d)->assertOk()->json('data.id');
        $this->getJson('/api/v1/public/announcements')->assertJsonCount(0, 'data');
        $d['published'] = true;
        $this->patchJson('/api/v1/management/content/announcements/'.$id, $d)->assertOk();
        $this->getJson('/api/v1/public/announcements')->assertJsonCount(1, 'data');
        $this->assertStringNotContainsString('<script', Content::first()->body);
        $this->deleteJson('/api/v1/management/content/announcements/'.$id)->assertOk();
        $this->getJson('/api/v1/public/announcements')->assertJsonCount(0, 'data');
    }

    public function test_contact_messages_can_be_trashed_restored_and_deleted_only_by_admin(): void
    {
        $this->postJson('/api/v1/public/contact-messages', ['name' => 'Synthetic Visitor', 'email' => 'visitor@example.test', 'message' => 'A synthetic message for testing.'])->assertCreated();
        $id = ContactMessage::first()->id;
        $u = User::factory()->create();
        Sanctum::actingAs($u);
        $this->getJson('/api/v1/management/messages')->assertForbidden();
        $u->forceFill(['role' => 'admin'])->save();
        $this->postJson("/api/v1/management/messages/$id/trash")->assertOk();
        $this->assertSoftDeleted('contact_messages', ['id' => $id]);
        $this->postJson("/api/v1/management/messages/$id/restore")->assertOk();
        $this->assertDatabaseHas('contact_messages', ['id' => $id, 'deleted_at' => null]);
        $this->postJson("/api/v1/management/messages/$id/destroy", ['confirm' => true])->assertUnprocessable();
        $this->postJson("/api/v1/management/messages/$id/trash")->assertOk();
        $this->postJson("/api/v1/management/messages/$id/destroy", ['confirm' => true])->assertOk();
        $this->assertDatabaseCount('contact_messages', 0);
    }
}
