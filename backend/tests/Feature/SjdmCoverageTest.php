<?php

namespace Tests\Feature;

use App\Models\Incident;
use App\Models\IncidentCategory;
use App\Models\User;
use App\Services\SjdmCoverage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SjdmCoverageTest extends TestCase
{
    use RefreshDatabase;

    private function payload(float $latitude, float $longitude, string $source = 'manual'): array
    {
        return [
            'category_id' => IncidentCategory::where('active', true)->firstOrFail()->id,
            'description' => 'SYNTHETIC coverage validation test', 'latitude' => $latitude, 'longitude' => $longitude,
            'location_source' => $source, 'idempotency_key' => (string) Str::uuid(),
        ] + ($source === 'gps' ? ['accuracy_m' => 10, 'location_observed_at' => now()->toISOString()] : []);
    }

    public function test_full_polygon_distinguishes_inside_points_from_bounding_box_corners(): void
    {
        $coverage = new SjdmCoverage;
        // Expected classifications were independently checked with GEOS Covers.
        foreach ([[14.8139, 121.0453], [14.823374, 121.044868], [14.852, 121.075], [14.805, 121.038], [14.825, 121.076]] as [$lat, $lon]) {
            $this->assertTrue($coverage->contains($lat, $lon));
        }
        foreach ([[14.5995, 120.9842], [14.676, 121.043], [14.86, 121.02], [14.77, 121.16], [14.77, 121.025], [14.86, 121.16]] as [$lat, $lon]) {
            $this->assertFalse($coverage->contains($lat, $lon));
        }
        $this->assertFalse($coverage->contains(NAN, 121.0453));
        $this->assertFalse($coverage->contains(14.8139, INF));
        $this->assertFalse($coverage->contains(121.0453, 14.8139), 'Latitude/longitude order must not be reversed.');
        $ring = json_decode(file_get_contents(resource_path('geo/san-jose-del-monte.geojson')), true)['geometry']['coordinates'][0];
        $this->assertTrue($coverage->contains($ring[0][1], $ring[0][0]), 'Boundary vertices count as covered.');
        $this->assertTrue($coverage->contains(($ring[0][1] + $ring[1][1]) / 2, ($ring[0][0] + $ring[1][0]) / 2), 'Boundary segments count as covered.');
    }

    public function test_manual_and_gps_reports_outside_coverage_are_rejected_without_side_effects(): void
    {
        Notification::fake();
        Sanctum::actingAs(User::factory()->create());
        foreach (['manual', 'gps'] as $source) {
            foreach ([[14.5995, 120.9842], [14.86, 121.02]] as [$lat, $lon]) {
                $this->postJson('/api/v1/incidents', $this->payload($lat, $lon, $source))->assertUnprocessable()
                    ->assertJsonPath('error.fields.location.0', SjdmCoverage::MESSAGE);
            }
        }
        $this->assertDatabaseCount('incidents', 0);
        $this->assertDatabaseCount('incident_status_histories', 0);
        $this->assertDatabaseCount('audit_logs', 0);
        Notification::assertNothingSent();
    }

    public function test_inside_and_boundary_locations_are_accepted_and_retry_is_idempotent(): void
    {
        Notification::fake();
        Sanctum::actingAs(User::factory()->create());
        foreach (['manual', 'gps'] as $source) {
            $payload = $this->payload(14.8139, 121.0453, $source);
            $id = $this->postJson('/api/v1/incidents', $payload)->assertCreated()->json('data.id');
            $this->postJson('/api/v1/incidents', $payload)->assertOk()->assertJsonPath('data.id', $id);
        }
        $this->postJson('/api/v1/incidents', $this->payload(14.8185063, 121.0165886))->assertCreated();
        $this->assertDatabaseCount('incidents', 3);
    }

    public function test_existing_outside_report_receipts_remain_accessible_without_creating_new_reports(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $payload = $this->payload(14.5995, 120.9842);
        ksort($payload);
        $report = Incident::create($payload + [
            'reporter_id' => $user->id, 'public_reference' => (string) Str::uuid(), 'received_at' => now(),
            'request_hash' => hash('sha256', json_encode($payload, JSON_PRESERVE_ZERO_FRACTION)),
        ]);
        $this->postJson('/api/v1/incidents', $payload)->assertOk()->assertJsonPath('data.id', $report->id);
        $this->getJson('/api/v1/incidents/receipt/'.$payload['idempotency_key'])->assertOk()->assertJsonPath('data.id', $report->id);
        $payload['idempotency_key'] = (string) Str::uuid();
        $this->postJson('/api/v1/incidents', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('incidents', 1);
        Notification::assertNothingSent();
    }
}
