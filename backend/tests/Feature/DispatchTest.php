<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\DispatchRule;
use App\Models\IncidentCategory;
use App\Services\Dispatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DispatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_unconfigured_outside_inactive_and_conflicting_jurisdictions_require_triage(): void
    {
        $a = Agency::where('type', 'FIRE')->firstOrFail();
        $b = Agency::where('type', 'PNP')->firstOrFail();
        $c = IncidentCategory::create(['name' => 'Synthetic event', 'agency_id' => $a->id]);
        $dispatch = app(Dispatch::class);
        $this->assertNull($dispatch->agency($c->id, 14.5, 121));
        $rule = DispatchRule::create(['name' => 'Synthetic rectangle', 'category_id' => $c->id, 'agency_id' => $a->id, 'south' => 14, 'north' => 15, 'west' => 120, 'east' => 122, 'active' => true]);
        $this->assertSame($a->id, $dispatch->agency($c->id, 14.5, 121));
        $this->assertNull($dispatch->agency($c->id, 0, 0));
        $a->update(['active' => false]);
        $this->assertNull($dispatch->agency($c->id, 14.5, 121));
        $a->update(['active' => true]);
        DispatchRule::create(['name' => 'Conflicting synthetic rectangle', 'category_id' => $c->id, 'agency_id' => $b->id, 'south' => 14, 'north' => 15, 'west' => 120, 'east' => 122, 'active' => true]);
        $this->assertNull($dispatch->agency($c->id, 14.5, 121));
    }
}
