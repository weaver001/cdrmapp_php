<?php

namespace App\Services;

use App\Models\Agency;
use App\Models\IncidentCategory;

class Dispatch
{
    public function agency(int $category): ?int
    {
        $agencyId = IncidentCategory::whereKey($category)->where('active', true)->value('agency_id');
        $agency = Agency::whereKey($agencyId)->where('active', true)->whereIn('type', array_keys(Agency::DEFAULT_NAMES))->first();

        return $agency ? (int) $agency->id : null;
    }
}
