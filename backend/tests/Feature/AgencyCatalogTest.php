<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\DispatchRule;
use App\Models\Incident;
use App\Models\IncidentCategory;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AgencyCatalogTest extends TestCase
{
    use RefreshDatabase;

    private const LABELS = ['Fire Department (BFP)', 'Police Department (PNP)', 'MDRRMO'];

    private function incident(User $reporter, Agency $agency, IncidentCategory $category): Incident
    {
        return Incident::create([
            'public_reference' => (string) Str::uuid(), 'reporter_id' => $reporter->id,
            'agency_id' => $agency->id, 'category_id' => $category->id,
            'description' => 'SYNTHETIC catalog compatibility check.',
            'latitude' => 14.8139, 'longitude' => 121.0453, 'location_source' => 'manual',
            'received_at' => now(), 'idempotency_key' => (string) Str::uuid(),
            'request_hash' => hash('sha256', 'synthetic catalog check'), 'is_test' => true,
        ]);
    }

    public function test_catalog_is_shared_by_api_and_web_and_seeding_does_not_duplicate_it(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);
        $this->assertDatabaseCount('agencies', 3);
        $this->assertDatabaseCount('incident_categories', 3);
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $this->assertSame(self::LABELS, array_column($this->getJson('/api/v1/agencies')->assertOk()->json('data'), 'name'));
        $this->assertSame(self::LABELS, array_column($this->getJson('/api/v1/incident-categories')->assertOk()->json('data'), 'name'));
        $user->forceFill(['role' => 'admin'])->save();
        $this->actingAs($user)->get('/dashboard')->assertOk()->assertSeeInOrder(self::LABELS);
        $this->get('/manage/users')->assertOk()->assertSeeInOrder(self::LABELS);
    }

    public function test_upgrade_preserves_records_assignments_dispatch_custom_categories_and_activation(): void
    {
        $fire = Agency::where('type', 'FIRE')->firstOrFail();
        $fire->update(['name' => 'Fire Department', 'jurisdiction' => 'Synthetic jurisdiction', 'emergency_phone' => 'TEST ONLY', 'active' => false]);
        $category = IncidentCategory::where('agency_id', $fire->id)->firstOrFail();
        $category->update(['name' => 'Fire Department', 'active' => false]);
        $custom = IncidentCategory::create(['name' => 'Custom rescue category', 'agency_id' => $fire->id]);
        $reporter = User::factory()->create();
        $officer = User::factory()->create();
        $officer->forceFill(['role' => 'officer', 'agency_id' => $fire->id])->save();
        $incident = $this->incident($reporter, $fire, $category);
        $rule = DispatchRule::create(['name' => 'Synthetic dispatch', 'agency_id' => $fire->id, 'category_id' => $category->id, 'south' => 14, 'north' => 15, 'west' => 120, 'east' => 122, 'active' => true]);

        $migration = require database_path('migrations/2026_10_22_000003_standardize_default_agencies.php');
        $migration->up();
        $migration->up();
        $this->seed(DatabaseSeeder::class);
        $this->assertDatabaseCount('agencies', 3);
        $this->assertDatabaseCount('incident_categories', 4);
        $this->assertSame('Fire Department (BFP)', $fire->fresh()->name);
        $this->assertSame('Fire Department (BFP)', $category->fresh()->name);
        $this->assertFalse($fire->fresh()->active);
        $this->assertFalse($category->fresh()->active);
        $this->assertSame('Synthetic jurisdiction', $fire->fresh()->jurisdiction);
        $this->assertSame('TEST ONLY', $fire->fresh()->emergency_phone);
        $this->assertSame($fire->id, $officer->fresh()->agency_id);
        $this->assertSame($fire->id, $incident->fresh()->agency_id);
        $this->assertSame($category->id, $incident->fresh()->category_id);
        $this->assertSame($fire->id, $rule->fresh()->agency_id);
        $this->assertSame($category->id, $rule->fresh()->category_id);
        $this->assertSame('Custom rescue category', $custom->fresh()->name);
    }

    public function test_only_admins_can_generate_reports_and_officers_retain_scoped_incident_access(): void
    {
        $reporter = User::factory()->create();
        foreach (Agency::inDisplayOrder()->get() as $agency) {
            $this->incident($reporter, $agency, IncidentCategory::where('agency_id', $agency->id)->firstOrFail());
        }
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'admin'])->save();
        Sanctum::actingAs($admin);
        $csv = $this->get('/api/v1/reports/export')->assertOk()->streamedContent();
        foreach (self::LABELS as $label) {
            $this->assertStringContainsString($label, $csv);
        }
        $this->getJson('/api/v1/reports')->assertOk()->assertJsonCount(3, 'data');
        $this->actingAs($admin)->get('/dashboard')->assertOk()->assertSee('id="export"', false)->assertSee('id="print"', false);
        foreach (Agency::inDisplayOrder()->get() as $agency) {
            $officer = User::factory()->create(['role' => 'officer', 'agency_id' => $agency->id]);
            $this->actingAs($officer);
            Sanctum::actingAs($officer);
            $this->getJson('/api/v1/reports')->assertForbidden();
            $this->get('/api/v1/reports/export')->assertForbidden();
            $this->get('/dashboard')->assertOk()->assertDontSee('id="export"', false)->assertDontSee('id="print"', false)->assertSee('Response watchlist');
            $this->get('/incident-map')->assertOk()->assertDontSee('id="export"', false)->assertDontSee('id="print"', false);
            $own = $this->getJson('/api/v1/incidents')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.agency_id', $agency->id)->json('data.0');
            $this->patchJson('/api/v1/incidents/'.$own['id'].'/status', ['status' => 'done'])->assertOk()->assertJsonPath('data.workflow_status', 'done');
        }
    }
}
