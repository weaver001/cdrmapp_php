<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\Content;
use App\Models\Incident;
use App\Models\IncidentCategory;
use App\Models\User;
use App\Notifications\Channels\FcmChannel;
use App\Notifications\IncidentChanged;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function report(string $status): Incident
    {
        $agency = Agency::where('type', 'FIRE')->firstOrFail();

        return Incident::create([
            'reporter_id' => User::factory()->create()->id, 'agency_id' => $agency->id,
            'category_id' => IncidentCategory::where('agency_id', $agency->id)->value('id'),
            'public_reference' => (string) Str::uuid(), 'description' => 'Synthetic status compatibility test',
            'latitude' => 14.8139, 'longitude' => 121.0453, 'address' => 'San Jose del Monte, Bulacan',
            'location_source' => 'manual', 'received_at' => now(), 'workflow_status' => $status,
            'idempotency_key' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64), 'is_test' => true,
        ]);
    }

    public function test_existing_statuses_filter_and_summarize_as_done_or_need_action_without_rewriting_history(): void
    {
        foreach (['done', 'resolved', 'closed', 'rejected', 'duplicate', 'need_action', 'submitted', 'received', 'verified', 'in_progress'] as $status) {
            $this->report($status);
        }
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $done = $this->getJson('/api/v1/reports?status=done')->assertOk()->assertJsonCount(5, 'data')->json('data');
        $this->assertSame(['done'], array_values(array_unique(array_column($done, 'workflow_status'))));
        $pending = $this->getJson('/api/v1/reports?status=need_action')->assertOk()->assertJsonCount(5, 'data')->json('data');
        $this->assertSame(['need_action'], array_values(array_unique(array_column($pending, 'workflow_status'))));
        $this->getJson('/api/v1/dashboard/summary')->assertOk()->assertJsonPath('data.by_status.done', 5)->assertJsonPath('data.by_status.need_action', 5);
        $this->getJson('/api/v1/reports?status=in_progress')->assertUnprocessable();
        $this->assertDatabaseHas('incidents', ['workflow_status' => 'resolved']);
        $export = $this->get('/api/v1/reports/export?status=done')->assertOk()->streamedContent();
        $this->assertStringContainsString('Latitude,Longitude', $export);
        $this->assertStringContainsString('14.8139,121.0453', $export);
        $this->assertStringContainsString('Done', $export);
    }

    public function test_done_undone_actions_are_scoped_and_test_reports_do_not_send_notifications(): void
    {
        Notification::fake();
        $incident = $this->report('submitted');
        Sanctum::actingAs(User::factory()->create(['role' => 'officer', 'agency_id' => Agency::where('type', 'PNP')->value('id')]));
        $this->patchJson('/api/v1/incidents/'.$incident->id.'/status', ['status' => 'done', 'note' => 'SYNTHETIC response: assistance completed.'])->assertForbidden();
        Sanctum::actingAs(User::factory()->create(['role' => 'officer', 'agency_id' => $incident->agency_id]));
        $this->patchJson('/api/v1/incidents/'.$incident->id.'/status', ['status' => 'done', 'note' => 'SYNTHETIC response: assistance completed.'])->assertOk()->assertJsonPath('data.workflow_status', 'done');
        $this->patchJson('/api/v1/incidents/'.$incident->id.'/status', ['status' => 'need_action'])->assertOk()->assertJsonPath('data.workflow_status', 'need_action');
        $this->assertDatabaseCount('incident_status_histories', 2);
        $this->assertDatabaseCount('audit_logs', 2);
        Notification::assertNothingSent();
    }

    public function test_marking_a_real_report_done_notifies_its_reporter_and_updates_their_history(): void
    {
        Notification::fake();
        config(['services.fcm.enabled' => true]);
        $incident = $this->report('need_action');
        $incident->update(['is_test' => false]);
        $reporter = $incident->reporter;
        $otherCitizen = User::factory()->create();
        Sanctum::actingAs(User::factory()->create(['role' => 'officer', 'agency_id' => $incident->agency_id]));
        $this->patchJson('/api/v1/incidents/'.$incident->id.'/status', ['status' => 'done', 'note' => 'SYNTHETIC response: assistance completed.'])->assertOk();
        Notification::assertSentTo($reporter, IncidentChanged::class, fn ($n, $channels) => $n->status === 'done'
            && $n->incidentId === $incident->id
            && in_array(FcmChannel::class, $channels, true));
        Notification::assertNotSentTo($otherCitizen, IncidentChanged::class);
        Sanctum::actingAs($reporter);
        $this->getJson('/api/v1/incidents')->assertOk()->assertJsonPath('data.0.workflow_status', 'done');
        $this->getJson('/api/v1/incidents/'.$incident->id)->assertOk()->assertJsonPath('data.workflow_status', 'done');
    }

    public function test_map_is_a_separate_authorized_page_and_dashboard_has_location_and_two_statuses(): void
    {
        $this->get('/incident-map')->assertRedirect('/login');
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->get('/dashboard')->assertOk()->assertSee('<th>Location</th>', false)
            ->assertSee('<th>View on map</th>', false)
            ->assertSee('value="done"', false)->assertSee('value="need_action"', false)
            ->assertDontSee('id="map"', false)->assertDontSee('data-map-key', false)
            ->assertDontSee('/manage/dispatch-rules')->assertDontSee('/manage/infographics');
        $this->get('/incident-map')->assertOk()->assertSee('id="map"', false)->assertDontSee('id="incidents"', false)->assertSee('Agency pin legend');
        $this->actingAs(User::factory()->create(['role' => 'officer', 'agency_id' => Agency::where('type', 'FIRE')->value('id')]));
        $this->get('/dashboard')->assertOk()->assertSee('<th>View on map</th>', false);
        $this->actingAs(User::factory()->create());
        $this->get('/incident-map')->assertForbidden();
    }

    public function test_removed_modules_are_unavailable_and_home_shows_announcements(): void
    {
        $infographic = Content::create(['type' => 'infographics', 'title' => 'Legacy infographic', 'body' => 'Old content', 'published' => true]);
        Content::create(['type' => 'announcements', 'title' => 'Current announcement', 'body' => 'Current content', 'published' => true]);
        $this->get('/')->assertOk()->assertSee('Current announcement')->assertDontSee('Legacy infographic');
        $this->get('/pages/infographics')->assertNotFound();
        $this->getJson('/api/v1/public/infographics')->assertNotFound();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        foreach (['dispatch-rules', 'infographics'] as $module) {
            $this->get('/manage/'.$module)->assertNotFound();
        }
        $this->getJson('/api/v1/management/dispatch-rules')->assertNotFound();
        $this->postJson('/api/v1/management/dispatch-rules', [])->assertNotFound();
        $this->patchJson('/api/v1/management/dispatch-rules/1', [])->assertNotFound();
        $this->getJson('/api/v1/management/content/infographics')->assertNotFound();
        $this->postJson('/api/v1/management/content/infographics', ['title' => 'Attempt', 'body' => 'Test', 'published' => true])->assertNotFound();
        $this->deleteJson('/api/v1/management/content/infographics/'.$infographic->id)->assertNotFound();
        $this->postJson('/api/v1/management/content/infographics/'.$infographic->id.'/image')->assertNotFound();
        $this->assertDatabaseHas('contents', ['id' => $infographic->id, 'deleted_at' => null]);
    }
}
