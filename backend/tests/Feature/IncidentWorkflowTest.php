<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\DispatchRule;
use App\Models\Incident;
use App\Models\IncidentCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IncidentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'citizen', ?int $agency = null): User
    {
        $u = User::factory()->create();
        $u->forceFill(['role' => $role, 'agency_id' => $agency])->save();

        return $u;
    }

    private function category(string $type = 'FIRE'): IncidentCategory
    {
        $a = Agency::firstOrCreate(['type' => $type], ['name' => $type]);

        $category = IncidentCategory::firstOrCreate(['name' => $type], ['agency_id' => $a->id]);
        DispatchRule::firstOrCreate(['category_id' => $category->id, 'agency_id' => $a->id], ['name' => 'SYNTHETIC TEST jurisdiction', 'south' => 14, 'north' => 15, 'west' => 120, 'east' => 122, 'active' => true]);

        return $category;
    }

    private function payload(?IncidentCategory $c = null): array
    {
        return ['category_id' => ($c ?? $this->category())->id, 'description' => 'SYNTHETIC TEST: smoke visible near an empty building.', 'latitude' => 14.5995, 'longitude' => 120.9842, 'location_source' => 'manual', 'idempotency_key' => (string) Str::uuid()];
    }

    public function test_submission_is_durable_idempotent_and_scoped(): void
    {
        $citizen = $this->user();
        Sanctum::actingAs($citizen);
        $d = $this->payload();
        $first = $this->postJson('/api/v1/incidents', $d)->assertCreated()->assertJsonPath('data.workflow_status', 'submitted')->json('data');
        $this->postJson('/api/v1/incidents', $d)->assertOk()->assertJsonPath('data.id', $first['id']);
        $this->assertDatabaseCount('incidents', 1);
        $this->getJson('/api/v1/incidents/receipt/'.$d['idempotency_key'])->assertOk()->assertJsonPath('data.id', $first['id']);
        $this->postJson('/api/v1/incidents', array_replace($d, ['description' => 'Different description']))->assertConflict();
        $other = $this->user();
        Sanctum::actingAs($other);
        $this->getJson('/api/v1/incidents/receipt/'.$d['idempotency_key'])->assertNotFound();
        $this->getJson('/api/v1/incidents/'.$first['id'])->assertForbidden();
        $this->getJson('/api/v1/incidents')->assertJsonCount(0, 'data');
        $pnp = $this->category('PNP');
        Sanctum::actingAs($this->user('officer', $pnp->agency_id));
        $this->getJson('/api/v1/incidents/'.$first['id'])->assertForbidden();
        $this->getJson('/api/v1/incidents')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/dashboard/summary')->assertJsonPath('data.total', 0);
        Sanctum::actingAs($this->user('officer', $this->category()->agency_id));
        $this->getJson('/api/v1/incidents/'.$first['id'])->assertOk();
    }

    public function test_status_requires_agency_and_valid_transitions_and_records_history(): void
    {
        Sanctum::actingAs($this->user());
        $id = $this->postJson('/api/v1/incidents', $this->payload())->json('data.id');
        $this->patchJson("/api/v1/incidents/$id/status", ['status' => 'received', 'note' => 'Test'])->assertForbidden();
        Sanctum::actingAs($this->user('officer', $this->category()->agency_id));
        $this->postJson("/api/v1/incidents/$id/verify", ['rationale' => 'Confirmed test event'])->assertUnprocessable();
        $this->patchJson("/api/v1/incidents/$id/status", ['status' => 'received', 'note' => 'Acknowledged'])->assertOk();
        $this->patchJson("/api/v1/incidents/$id/status", ['status' => 'verified', 'note' => 'Bypass'])->assertUnprocessable();
        $this->postJson("/api/v1/incidents/$id/verify", ['rationale' => 'Confirmed test event'])->assertOk()->assertJsonPath('data.verification_status', 'validated');
        foreach (['in_progress', 'resolved', 'closed'] as $s) {
            $this->patchJson("/api/v1/incidents/$id/status", ['status' => $s, 'note' => 'Synthetic workflow check'])->assertOk();
        }
        $this->patchJson("/api/v1/incidents/$id/status", ['status' => 'received', 'note' => 'Reopen'])->assertUnprocessable();
        $this->assertDatabaseCount('incident_status_histories', 6);
        $this->assertDatabaseCount('incident_verifications', 1);
    }

    public function test_invalid_coordinates_stale_gps_and_unverified_email_are_rejected(): void
    {
        $u = $this->user();
        Sanctum::actingAs($u);
        $d = $this->payload();
        $this->postJson('/api/v1/incidents', array_replace($d, ['latitude' => 91]))->assertUnprocessable();
        $this->postJson('/api/v1/incidents', array_replace($d, ['location_source' => 'gps', 'accuracy_m' => 15, 'location_observed_at' => now()->subHour()->toISOString()]))->assertUnprocessable();
        $u->forceFill(['email_verified_at' => null])->save();
        $this->postJson('/api/v1/incidents', $d)->assertForbidden();
        $this->assertDatabaseCount('incidents', 0);
    }

    public function test_heatmap_suppresses_unvalidated_test_rejected_and_small_cells(): void
    {
        $u = $this->user();
        Sanctum::actingAs($u);
        $c = $this->category();
        $ids = [];
        for ($x = 0; $x < 8; $x++) {
            $ids[] = $this->postJson('/api/v1/incidents', $this->payload($c))->assertCreated()->json('data.id');
        }
        Incident::whereIn('id', array_slice($ids, 0, 3))->update(['workflow_status' => 'verified', 'verification_status' => 'validated']);
        Incident::where('id', $ids[3])->update(['workflow_status' => 'verified', 'verification_status' => 'validated', 'is_test' => true]);
        Incident::where('id', $ids[4])->update(['workflow_status' => 'rejected', 'verification_status' => 'validated']);
        Sanctum::actingAs($this->user('officer', $c->agency_id));
        $this->getJson('/api/v1/analytics/heatmap')->assertOk()->assertJsonCount(1, 'data.points')->assertJsonPath('data.points.0.weight', 3)->assertJsonMissing(['reporter_id' => $u->id]);
        $this->getJson('/api/v1/analytics/heatmap?west=0&east=1&south=0&north=1')->assertJsonCount(0, 'data.points');
        Sanctum::actingAs($this->user('officer', $this->category('PNP')->agency_id));
        $this->getJson('/api/v1/analytics/heatmap')->assertJsonCount(0, 'data.points');
        Sanctum::actingAs($u);
        $this->getJson('/api/v1/analytics/heatmap')->assertForbidden();
    }

    public function test_inactive_agency_routes_to_triage_and_only_admin_can_reroute(): void
    {
        $c = $this->category();
        Agency::find($c->agency_id)->update(['active' => false]);
        Sanctum::actingAs($this->user());
        $id = $this->postJson('/api/v1/incidents', $this->payload($c))->assertCreated()->assertJsonPath('data.needs_triage', true)->json('data.id');
        Sanctum::actingAs($this->user('officer', $c->agency_id));
        $this->getJson("/api/v1/incidents/$id")->assertForbidden();
        Sanctum::actingAs($this->user('admin'));
        $this->postJson("/api/v1/incidents/$id/assign", ['agency_id' => $c->agency_id, 'note' => 'Manually triaged'])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'incident.assignment', 'record_id' => $id]);
    }
}
