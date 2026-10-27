<?php

namespace App\Http\Controllers;

use App\Http\Resources\IncidentResource;
use App\Services\IncidentFilters;
use App\Services\IncidentWorkflow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;

class AnalyticsController extends Controller
{
    private function staff(Request $r): void
    {
        abort_unless($r->user()->isStaff(), 403);
    }

    public function summary(Request $r, IncidentFilters $filters)
    {
        $this->staff($r);
        $q = $filters->query($r);

        return ['data' => ['total' => (clone $q)->count(), 'by_status' => ['done' => (clone $q)->reportStatus('done')->count(), 'need_action' => (clone $q)->reportStatus('need_action')->count()], 'by_agency' => (clone $q)->selectRaw('agency_id, COUNT(*) as total')->groupBy('agency_id')->get(), 'validated' => (clone $q)->where('verification_status', 'validated')->count(), 'latest' => IncidentResource::collection((clone $q)->latest('id')->limit(6)->get())]];
    }

    public function heatmap(Request $r, IncidentFilters $filters)
    {
        $this->staff($r);
        $r->validate(['exploratory' => 'nullable|boolean']);
        $q = $filters->query($r)->where('is_test', false)->whereNotIn('verification_status', ['rejected', 'duplicate'])->whereNotIn('workflow_status', ['rejected', 'duplicate']);
        if (! $r->boolean('exploratory')) {
            $q->where('verification_status', 'validated')->where(fn ($q) => $q->where('location_source', 'manual')->orWhere('accuracy_m', '<=', 100));
        }
        // Approximately 1 km grid. Suppress cells with fewer than three events.
        $cacheKey = 'heatmap:'.hash('sha256', json_encode([$r->user()->id, $r->user()->role, $r->user()->agency_id, $r->query(), Cache::get('analytics-version', 0)]));
        $points = Cache::remember($cacheKey, 30, fn () => $q->selectRaw('FLOOR(latitude * 100) as lat_cell, FLOOR(longitude * 100) as lng_cell, COUNT(*) as weight')->groupBy('lat_cell', 'lng_cell')->havingRaw('COUNT(*) >= 3')->orderByDesc('weight')->limit(1000)->get()->map(fn ($p) => ['latitude' => ($p->lat_cell + 0.5) / 100, 'longitude' => ($p->lng_cell + 0.5) / 100, 'weight' => (int) $p->weight]));

        return ['data' => ['points' => $points, 'minimum_cell_count' => 3, 'grid_degrees' => 0.01, 'exploratory' => $r->boolean('exploratory'), 'disclaimer' => 'Descriptive density of reported incidents; not a prediction or response guarantee.', 'filters' => $r->query()]];
    }

    public function trends(Request $r, IncidentFilters $filters)
    {
        $this->staff($r);

        return ['data' => $filters->query($r)->selectRaw('DATE(received_at) as date, COUNT(*) as total')->groupBy('date')->orderBy('date')->limit(366)->get()];
    }

    public function export(Request $r, IncidentFilters $filters)
    {
        Gate::authorize('admin');
        $q = $filters->query($r)->orderBy('id');
        abort_if((clone $q)->count() > 10000, 422, 'Narrow filters to at most 10,000 reports.');

        return response()->streamDownload(function () use ($q, $r) {
            $f = fopen('php://output', 'w');
            $write = function ($values) use ($f) {
                fputcsv($f, array_map(fn ($v) => preg_match('/^[=+@\-\t\r]/', (string) $v) ? "'".$v : $v, $values), ',', '"', '');
            };
            $write(['Created UTC', now()->toISOString(), 'Authorized user ID', $r->user()->id]);
            $write(['Filters', json_encode($r->query())]);
            $write(['Reference', 'Agency ID', 'Agency', 'Status', 'Received UTC', 'Description', 'Location', 'Latitude', 'Longitude', 'Response notes']);
            foreach ($q->with(['agency', 'responses' => fn ($responses) => $responses->orderBy('occurred_at')->orderBy('id'), 'responses.officer:id,name'])->lazyById(500) as $i) {
                $notes = $i->responses->map(fn ($response) => '['.$response->occurred_at->toISOString().'] '.($response->officer?->name ?? 'Staff #'.$response->officer_id).': '.$response->note)->implode("\n\n");
                $write([$i->public_reference, $i->agency_id, $i->agency?->name ?? 'Needs triage', IncidentWorkflow::STATUSES[$i->workflow_status], $i->received_at->toISOString(), $i->description, $i->address, $i->latitude, $i->longitude, $notes]);
            }fclose($f);
        }, 'incidents.csv', ['Content-Type' => 'text/csv']);
    }
}
