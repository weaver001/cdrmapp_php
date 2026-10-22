<?php

namespace App\Services;

use App\Models\Agency;
use App\Models\AgencyDispatchRoute;
use App\Models\IncidentCategory;

class Dispatch
{
    public function agency(int $category): ?int
    {
        $agencyId = IncidentCategory::whereKey($category)->where('active', true)->value('agency_id');
        $route = AgencyDispatchRoute::where('agency_id', $agencyId)->where('active', true)
            ->whereHas('agency', fn ($query) => $query->where('active', true)->whereIn('type', array_keys(Agency::DEFAULT_NAMES)))
            ->first();

        return $route ? (int) $route->agency_id : null;
    }
}
