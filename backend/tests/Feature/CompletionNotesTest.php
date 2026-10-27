<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\IncidentCategory;
use App\Models\IncidentResponse;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CompletionNotesTest extends TestCase
{
    use RefreshDatabase;

    private function report(): Incident
    {
        $agency = Agency::where('type', 'FIRE')->firstOrFail();

        return Incident::create([
            'reporter_id' => User::factory()->create()->id, 'agency_id' => $agency->id,
            'category_id' => IncidentCategory::where('agency_id', $agency->id)->value('id'),
            'public_reference' => (string) Str::uuid(), 'description' => 'SYNTHETIC completion test',
            'latitude' => 14.8139, 'longitude' => 121.0453, 'location_source' => 'manual',
            'received_at' => now(), 'idempotency_key' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64),
        ]);
    }

    public function test_completion_without_valid_notes_never_changes_status_or_creates_logs(): void
    {
        Notification::fake();
        $report = $this->report();
        Sanctum::actingAs(User::factory()->create(['role' => 'officer', 'agency_id' => $report->agency_id]));
        foreach ([[], ['note' => null], ['note' => ''], ['note' => '   '], ['note' => str_repeat('x', 2001)]] as $payload) {
            $this->patchJson('/api/v1/incidents/'.$report->id.'/status', $payload + ['status' => 'done'])
                ->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['note']]]);
        }
        $this->assertSame('need_action', $report->fresh()->workflow_status);
        $this->assertDatabaseCount('incident_responses', 0);
        $this->assertDatabaseCount('incident_status_histories', 0);
        $this->assertDatabaseCount('audit_logs', 0);
        Notification::assertNothingSent();
    }

    public function test_admin_and_officer_completion_notes_are_preserved_across_undo_and_recompletion(): void
    {
        Notification::fake();
        foreach (['admin', 'officer'] as $role) {
            $report = $this->report();
            $actor = User::factory()->create(['role' => $role, 'agency_id' => $report->agency_id]);
            Sanctum::actingAs($actor);
            $note = "SYNTHETIC response completed.\n<script>plain text</script>";
            $url = '/api/v1/incidents/'.$report->id;
            $this->patchJson($url.'/status', ['status' => 'done', 'note' => $note])->assertOk()->assertJsonPath('data.workflow_status', 'done');
            $this->patchJson($url.'/status', ['status' => 'done', 'note' => $note])->assertOk();
            $this->assertSame(1, $report->responses()->count(), 'Repeated requests must not duplicate completion notes.');
            $this->getJson($url)->assertOk()->assertJsonPath('data.responses.0.note', $note)
                ->assertJsonPath('data.responses.0.officer.name', $actor->name)
                ->assertJsonMissingPath('data.responses.0.officer.email');
            $response = $report->responses()->sole();
            $this->assertSame($actor->id, $response->officer_id);
            $this->assertNotNull($response->occurred_at);
            $this->assertSame($note, AuditLog::where('record_id', $report->id)->where('action', 'incident.status')->sole()->metadata['after']['note']);
            $this->patchJson($url.'/status', ['status' => 'need_action'])->assertOk();
            $this->patchJson($url.'/status', ['status' => 'done', 'note' => 'SYNTHETIC follow-up completed.'])->assertOk();
            $this->assertSame(2, $report->responses()->count());
            $this->assertSame($note, $report->responses()->oldest('id')->first()->note);
            Sanctum::actingAs(User::factory()->create(['role' => 'officer', 'agency_id' => Agency::where('type', 'PNP')->value('id')]));
            $this->getJson($url)->assertForbidden();
            $this->patchJson($url.'/status', ['status' => 'done', 'note' => 'Not authorized'])->assertForbidden();
        }
    }

    public function test_failure_to_save_response_rolls_back_the_status_and_notifications(): void
    {
        Notification::fake();
        $report = $this->report();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $event = 'eloquent.creating: '.IncidentResponse::class;
        Event::listen($event, fn () => throw new \RuntimeException('Synthetic database write failure'));
        try {
            $this->patchJson('/api/v1/incidents/'.$report->id.'/status', ['status' => 'done', 'note' => 'SYNTHETIC response'])->assertServerError();
        } finally {
            Event::forget($event);
        }
        $this->assertSame('need_action', $report->fresh()->workflow_status);
        $this->assertDatabaseCount('incident_responses', 0);
        $this->assertDatabaseCount('incident_status_histories', 0);
        Notification::assertNothingSent();
    }

    public function test_csv_includes_all_response_notes_in_one_cell_with_authors_and_utc_times(): void
    {
        $report = $this->report();
        $withoutNotes = $this->report();
        $excluded = $this->report();
        $excluded->update(['description' => 'Excluded fixture']);
        $actor = User::factory()->create(['role' => 'admin', 'name' => 'Synthetic, "Responder"']);
        $earlierNote = "=SYNTHETIC(), assistance completed\nSecond line with \"quotes\".";
        $laterNote = 'SYNTHETIC follow-up completed.';
        IncidentResponse::create(['incident_id' => $report->id, 'officer_id' => $actor->id, 'note' => $laterNote, 'occurred_at' => now(), 'outcome' => 'Done']);
        $earlier = IncidentResponse::create(['incident_id' => $report->id, 'officer_id' => $actor->id, 'note' => $earlierNote, 'occurred_at' => now()->subMinute(), 'outcome' => 'Done']);
        IncidentResponse::create(['incident_id' => $excluded->id, 'officer_id' => $actor->id, 'note' => 'MUST NOT EXPORT', 'occurred_at' => now()]);
        Sanctum::actingAs($actor);
        $csv = $this->get('/api/v1/reports/export?q=SYNTHETIC%20completion%20test')->assertOk()->streamedContent();
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, $csv);
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
            $rows[] = $row;
        }
        fclose($stream);
        $this->assertCount(5, $rows, 'Multiline response notes must not create extra report rows.');
        $headers = $rows[2];
        $first = array_combine($headers, $rows[3]);
        $second = array_combine($headers, $rows[4]);
        $this->assertSame($report->public_reference, $first['Reference']);
        $this->assertStringContainsString($earlierNote, $first['Response notes']);
        $this->assertStringContainsString($laterNote, $first['Response notes']);
        $this->assertStringContainsString($actor->name, $first['Response notes']);
        $this->assertStringContainsString($earlier->occurred_at->toISOString(), $first['Response notes']);
        $this->assertLessThan(strpos($first['Response notes'], $laterNote), strpos($first['Response notes'], $earlierNote));
        $this->assertStringStartsWith('[', $first['Response notes'], 'Note content must not become a spreadsheet formula.');
        $this->assertSame($withoutNotes->public_reference, $second['Reference']);
        $this->assertSame('', $second['Response notes']);
        $this->assertStringNotContainsString('MUST NOT EXPORT', $csv);
    }
}
