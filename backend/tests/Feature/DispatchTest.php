<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\AgencyDispatchRoute;
use App\Models\IncidentCategory;
use App\Models\User;
use App\Services\Dispatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DispatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_category_routes_to_its_agency_without_geographical_rules(): void
    {
        foreach (Agency::whereIn('type', array_keys(Agency::DEFAULT_NAMES))->get() as $agency) {
            $category = IncidentCategory::where('agency_id', $agency->id)->firstOrFail();
            $this->assertSame($agency->id, app(Dispatch::class)->agency($category->id));
        }
    }

    public function test_disabled_missing_and_inactive_routing_require_triage(): void
    {
        $agency = Agency::where('type', 'FIRE')->firstOrFail();
        $category = IncidentCategory::where('agency_id', $agency->id)->firstOrFail();
        $route = AgencyDispatchRoute::where('agency_id', $agency->id)->firstOrFail();
        $dispatch = app(Dispatch::class);
        $route->update(['active' => false]);
        $this->assertNull($dispatch->agency($category->id));
        $route->update(['active' => true]);
        $agency->update(['active' => false]);
        $this->assertNull($dispatch->agency($category->id));
        $agency->update(['active' => true]);
        $category->update(['active' => false]);
        $this->assertNull($dispatch->agency($category->id));
        $category->update(['active' => true]);
        $route->delete();
        $this->assertNull($dispatch->agency($category->id));
        $unmapped = IncidentCategory::create(['name' => 'Unmapped category']);
        $this->assertNull($dispatch->agency($unmapped->id));
    }

    public function test_only_admin_can_change_agency_routing_and_repeated_saves_do_not_duplicate_it(): void
    {
        $agency = Agency::where('type', 'PNP')->firstOrFail();
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/v1/management/dispatch-rules', ['agency_id' => $agency->id, 'active' => false])->assertForbidden();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $id = $this->postJson('/api/v1/management/dispatch-rules', ['agency_id' => (string) $agency->id, 'active' => false])->assertOk()->assertJsonPath('data.active', false)->json('data.id');
        $this->patchJson('/api/v1/management/dispatch-rules/'.$id, ['agency_id' => (string) $agency->id, 'active' => true])->assertOk()->assertJsonPath('data.active', true);
        $this->postJson('/api/v1/management/dispatch-rules', ['agency_id' => $agency->id, 'active' => false])->assertOk()->assertJsonPath('data.id', $id);
        $this->assertSame(1, AgencyDispatchRoute::where('agency_id', $agency->id)->count());
        $this->getJson('/api/v1/management/dispatch-rules')->assertOk()->assertJsonCount(3, 'data');
        $this->postJson('/api/v1/management/dispatch-rules', ['agency_id' => 999999, 'active' => true])->assertUnprocessable();
    }
}
