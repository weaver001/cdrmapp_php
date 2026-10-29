<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Content;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuditManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_password_reset_reports_success_only_when_the_link_is_sent(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $user = User::factory()->create(['role' => 'officer']);
        Password::shouldReceive('sendResetLink')->once()->with(['email' => $user->email])->andReturn(Password::RESET_LINK_SENT);
        $this->postJson('/api/v1/management/users/'.$user->id.'/reset-password', ['confirm' => true])->assertOk()->assertJsonPath('data.message', 'Password reset requested. The user must update their password before accessing the system.');
    }

    public function test_throttled_password_reset_is_reported_as_an_error(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $user = User::factory()->create(['role' => 'officer']);
        Password::shouldReceive('sendResetLink')->once()->andReturn(Password::RESET_THROTTLED);
        $this->postJson('/api/v1/management/users/'.$user->id.'/reset-password', ['confirm' => true])->assertUnprocessable();
        $this->assertFalse($user->fresh()->must_change_password);
    }

    public function test_admin_forms_have_clear_buttons_and_simple_agency_routing(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        foreach (['users', 'announcements', 'services', 'team-members', 'contact-details', 'faqs'] as $module) {
            $this->get('/manage/'.$module)->assertOk()->assertSee('Clear Field')->assertDontSee('New item')->assertDontSee('New account');
        }
    }

    public function test_audit_identifies_actor_and_content_after_update_and_removal(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'name' => 'Synthetic Administrator']);
        Sanctum::actingAs($admin);
        $data = ['title' => 'Readiness notice', 'body' => 'Original description', 'published' => true];
        $id = $this->postJson('/api/v1/management/content/announcements', $data)->assertOk()->json('data.id');
        $data['title'] = 'Updated readiness notice';
        $this->patchJson('/api/v1/management/content/announcements/'.$id, $data)->assertOk();
        $log = AuditLog::where('action', 'content.updated')->firstOrFail();
        $this->assertSame('Readiness notice', $log->metadata['before']['title']);
        $this->assertSame('Updated readiness notice', $log->metadata['after']['title']);
        $this->deleteJson('/api/v1/management/content/announcements/'.$id)->assertOk();
        $admin->update(['name' => 'Renamed Administrator']);
        $this->getJson('/api/v1/management/audit')->assertOk()
            ->assertJsonPath('data.0.actor_name', 'Synthetic Administrator')
            ->assertJsonPath('data.0.record_label', 'Updated readiness notice')
            ->assertJsonPath('data.0.metadata.before.body', 'Original description')
            ->assertJsonPath('data.0.action', 'content.deleted');
        $this->assertSoftDeleted(Content::class, ['id' => $id]);
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/management/audit')->assertForbidden();
    }

    public function test_account_creation_and_updates_are_identified_without_storing_credentials(): void
    {
        Notification::fake();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $data = ['name' => 'Synthetic Account', 'email' => 'synthetic-account@example.test', 'role' => 'citizen', 'active' => true];
        $id = $this->postJson('/api/v1/management/users', $data)->assertOk()->json('data.id');
        $data['active'] = false;
        $this->patchJson('/api/v1/management/users/'.$id, $data)->assertOk();
        $log = AuditLog::where('action', 'user.updated')->firstOrFail();
        $this->assertSame('Synthetic Account', $log->record_label);
        $this->assertTrue($log->metadata['before']['active']);
        $this->assertFalse($log->metadata['after']['active']);
        $created = AuditLog::where('action', 'user.created')->firstOrFail();
        $this->assertSame($data['email'], $created->metadata['after']['email']);
        $this->assertArrayNotHasKey('password', $created->metadata['after']);
        $this->assertArrayNotHasKey('remember_token', $created->metadata['after']);
    }

    public function test_created_account_is_reported_when_mail_delivery_fails(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        Password::shouldReceive('sendResetLink')->once()->andThrow(new \RuntimeException('Synthetic mail failure'));
        $response = $this->postJson('/api/v1/management/users', ['name' => 'Synthetic Account', 'email' => 'synthetic-mail-failure@example.test', 'role' => 'citizen', 'active' => true])->assertOk();
        $this->assertStringContainsString('Account created successfully.', $response->json('message'));
        $this->assertStringContainsString('Password invitation could not be sent.', $response->json('message'));
        $this->assertDatabaseHas('users', ['email' => 'synthetic-mail-failure@example.test']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.created', 'record_id' => $response->json('data.id')]);
    }
}
