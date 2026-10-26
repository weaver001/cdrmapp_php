<?php

namespace App\Services;

use App\Models\Incident;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class IncidentFilters
{
    public function query(Request $r)
    {
        $d = $r->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from', 'agency_id' => 'nullable|integer|exists:agencies,id', 'category_id' => 'nullable|integer|exists:incident_categories,id', 'status' => ['nullable', Rule::in(array_keys(IncidentWorkflow::TRANSITIONS))], 'q' => 'nullable|string|max:100', 'page' => 'nullable|integer|min:1', 'west' => 'nullable|numeric|between:-180,180', 'east' => 'required_with:west|numeric|between:-180,180|gte:west', 'south' => 'required_with:west|numeric|between:-90,90', 'north' => 'required_with:west|numeric|between:-90,90|gte:south']);
        $q = Incident::visibleTo($r->user());
        if (! empty($d['from'])) {
            $q->where('received_at', '>=', Carbon::parse($d['from'], 'Asia/Manila')->startOfDay()->utc());
        }
        if (! empty($d['to'])) {
            $q->where('received_at', '<', Carbon::parse($d['to'], 'Asia/Manila')->startOfDay()->addDay()->utc());
        }
        foreach (['agency_id', 'category_id'] as $k) {
            if (! empty($d[$k])) {
                $q->where($k, $d[$k]);
            }
        }
        if (! empty($d['status'])) {
            $q->reportStatus($d['status']);
        }
        if (! empty($d['q'])) {
            $q->where(fn ($q) => $q->where('public_reference', 'like', '%'.$d['q'].'%')->orWhere('description', 'like', '%'.$d['q'].'%'));
        }
        if (isset($d['west'])) {
            $q->whereBetween('longitude', [$d['west'], $d['east']])->whereBetween('latitude', [$d['south'], $d['north']]);
        }

        return $q;
    }
}
