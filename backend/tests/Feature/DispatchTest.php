<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\IncidentCategory;
use App\Services\Dispatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_inactive_agencies_categories_and_missing_mappings_require_triage(): void
    {
        $agency = Agency::where('type', 'FIRE')->firstOrFail();
        $category = IncidentCategory::where('agency_id', $agency->id)->firstOrFail();
        $dispatch = app(Dispatch::class);
        $agency->update(['active' => false]);
        $this->assertNull($dispatch->agency($category->id));
        $agency->update(['active' => true]);
        $category->update(['active' => false]);
        $this->assertNull($dispatch->agency($category->id));
        $unmapped = IncidentCategory::create(['name' => 'Unmapped category']);
        $this->assertNull($dispatch->agency($unmapped->id));
    }
}
