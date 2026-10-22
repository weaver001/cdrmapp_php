<?php

namespace App\Services;

use App\Models\Agency;
use App\Models\DispatchRule;

class Dispatch
{
    public function agency(int $category, float $latitude, float $longitude): ?int
    {
        $agencies = DispatchRule::where('category_id', $category)->where('active', true)->where('south', '<=', $latitude)->where('north', '>=', $latitude)->where('west', '<=', $longitude)->where('east', '>=', $longitude)->whereIn('agency_id', Agency::where('active', true)->select('id'))->distinct()->pluck('agency_id');

        // Missing or conflicting geographical rules require human triage.
        return $agencies->count() === 1 ? (int) $agencies->first() : null;
    }
}
