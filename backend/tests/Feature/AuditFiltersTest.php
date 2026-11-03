<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\AuditLog;
use App\Models\Content;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditFiltersTest extends TestCase
{
    use RefreshDatabase;

    public function test_today_uses_manila_boundaries_and_clear_includes_older_entries(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 3)->setTime(5, 0));
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);
        foreach (['2026-11-02 15:59:59', '2026-11-02 16:00:00', '2026-11-03 15:59:59', '2026-11-03 16:00:00'] as $time) {
            AuditLog::create(['actor_id' => $admin->id, 'action' => 'content.updated', 'record_type' => 'content', 'record_id' => 1, 'metadata' => [], 'created_at' => $time]);
        }
        $this->getJson('/api/v1/management/audit')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.created_at', '2026-11-03T15:59:59.000000Z');
        $this->getJson('/api/v1/management/audit?from=&to=')->assertOk()->assertJsonCount(4, 'data');
        $this->get('/manage/audit')->assertOk()->assertSee('Audit History')->assertDontSee('Manage Audit')->assertSee('id="audit-filters"', false)->assertSee('Clear Filter');
    }

    public function test_filters_combine_and_saved_agency_survives_record_removal(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $fire = Agency::where('type', 'FIRE')->firstOrFail();
        $police = Agency::where('type', 'PNP')->firstOrFail();
        $officer = User::factory()->create(['role' => 'officer', 'agency_id' => $fire->id]);
        $entry = AuditLog::recordChange($admin, 'user.updated', $officer, [], ['email' => 'historical@example.test', 'agency_id' => $fire->id], 'Historical officer');
        $officer->update(['agency_id' => $police->id]);
        $this->actingAs($admin);
        $query = http_build_query(['from' => '', 'to' => '', 'actor_id' => $admin->id, 'action' => 'updated', 'module' => 'users', 'agency_id' => $fire->id, 'q' => 'historical@example.test']);
        $this->getJson('/api/v1/management/audit?'.$query)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $entry->id);
        $this->getJson('/api/v1/management/audit?from=&to=&module=profile')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $entry->id);
        $this->getJson('/api/v1/management/audit?from=&to=&agency_id='.$police->id)->assertOk()->assertJsonCount(0, 'data');
        $content = Content::create(['type' => 'announcements', 'title' => 'Removed title', 'body' => 'Test', 'published' => false]);
        $deleted = AuditLog::recordChange($admin, 'content.deleted', $content, ['title' => $content->title], []);
        $content->delete();
        $this->getJson('/api/v1/management/audit?from=&to=&action=deleted&module=content&q=Removed')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $deleted->id);
        $this->getJson('/api/v1/management/audit?from=&to=&q=%25')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_done_and_undone_are_separate_actions_and_invalid_filters_are_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        foreach (['done', 'need_action'] as $status) {
            AuditLog::create(['actor_id' => $admin->id, 'action' => 'incident.status', 'record_type' => 'incident', 'record_id' => 1, 'metadata' => ['after' => ['status' => $status]]]);
        }
        $this->actingAs($admin);
        $this->getJson('/api/v1/management/audit?action=done')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.metadata.after.status', 'done');
        $this->getJson('/api/v1/management/audit?action=undone')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.metadata.after.status', 'need_action');
        foreach (['action=invalid', 'module=invalid', 'from=invalid', 'from=2026-11-03&to=2026-11-01', 'actor_id=-1'] as $query) {
            $this->getJson('/api/v1/management/audit?'.$query)->assertUnprocessable();
        }
        $this->actingAs(User::factory()->create(['role' => 'officer']));
        $this->getJson('/api/v1/management/audit')->assertForbidden();
        $this->get('/manage/audit')->assertForbidden();
    }
}
