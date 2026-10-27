<?php

namespace App\Http\Controllers;

use App\Http\Resources\IncidentResource;
use App\Models\Agency;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\IncidentCategory;
use App\Models\IncidentResponse;
use App\Models\IncidentStatusHistory;
use App\Models\IncidentVerification;
use App\Models\User;
use App\Services\Dispatch;
use App\Services\IncidentFilters;
use App\Services\IncidentWorkflow;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class IncidentController extends Controller
{
    public function index(Request $r, IncidentFilters $filters)
    {
        return IncidentResource::collection($filters->query($r)->with(['agency', 'category'])->latest('id')->paginate(25));
    }

    public function receipt(Request $r, string $key)
    {
        abort_unless($r->user()->role === 'citizen', 403);

        return new IncidentResource(Incident::where('reporter_id', $r->user()->id)->where('idempotency_key', $key)->firstOrFail());
    }

    public function show(Incident $incident)
    {
        Gate::authorize('view', $incident);

        return new IncidentResource($incident->load(['agency', 'category', 'histories', 'responses.officer:id,name', 'reporter', 'attachments']));
    }

    public function store(Request $r, IncidentWorkflow $flow)
    {
        abort_unless($r->user()->role === 'citizen', 403, 'Only citizens may submit incidents.');
        $d = $r->validate(['category_id' => ['required', 'integer', Rule::exists('incident_categories', 'id')->where('active', true)], 'description' => 'required|string|min:5|max:5000', 'latitude' => 'required|numeric|between:-90,90', 'longitude' => 'required|numeric|between:-180,180', 'accuracy_m' => 'nullable|numeric|min:0|max:100000', 'location_source' => 'required|in:gps,manual', 'location_observed_at' => 'required_if:location_source,gps|nullable|date|before_or_equal:now', 'address' => 'nullable|string|max:255', 'incident_at' => 'nullable|date|before_or_equal:now', 'idempotency_key' => 'required|uuid']);

        ksort($d);
        $hash = hash('sha256', json_encode($d, JSON_PRESERVE_ZERO_FRACTION));
        $existing = Incident::where('reporter_id', $r->user()->id)->where('idempotency_key', $d['idempotency_key'])->first();
        if ($existing) {
            return $this->replay($existing, $hash);
        }
        if ($d['location_source'] === 'gps' && (empty($d['accuracy_m']) || Carbon::parse($d['location_observed_at'])->lt(now()->subMinutes(5)))) {
            throw ValidationException::withMessages(['location_source' => 'GPS must be fresh with accuracy metadata. Choose a manual map pin if unavailable.']);
        }
        try {
            $i = DB::transaction(function () use ($r, $d, $hash, $flow) {
                $cat = IncidentCategory::findOrFail($d['category_id']);
                $agencyId = app(Dispatch::class)->agency($cat->id);
                $i = Incident::create($d + ['reporter_id' => $r->user()->id, 'agency_id' => $agencyId, 'public_reference' => (string) Str::uuid(), 'received_at' => now(), 'request_hash' => $hash]);
                IncidentStatusHistory::create(['incident_id' => $i->id, 'actor_id' => $r->user()->id, 'new_status' => 'need_action', 'note' => 'Durably received by the server.']);
                AuditLog::recordChange($r->user(), 'incident.created', $i, [], $i->only(['category_id', 'agency_id', 'workflow_status']), $i->public_reference);
                $flow->notify($i);

                return $i;
            });
        } catch (UniqueConstraintViolationException $e) {
            $i = Incident::where('reporter_id', $r->user()->id)->where('idempotency_key', $d['idempotency_key'])->first();
            if (! $i) {
                throw $e;
            }

            return $this->replay($i, $hash);
        }

        return (new IncidentResource($i))->response()->setStatusCode(201);
    }

    private function replay(Incident $i, string $hash)
    {
        abort_unless(hash_equals($i->request_hash, $hash), 409, 'This idempotency key was used for different content.');

        return new IncidentResource($i);
    }

    public function status(Request $r, Incident $incident, IncidentWorkflow $flow)
    {
        Gate::authorize('update', $incident);
        $d = $r->validate(['status' => ['required', Rule::in(array_keys(IncidentWorkflow::STATUSES))], 'note' => 'required_if:status,done|nullable|string|max:2000']);
        DB::transaction(function () use ($incident, $r, $flow, $d) {
            $i = Incident::lockForUpdate()->findOrFail($incident->id);
            Gate::authorize('update', $i);
            $flow->transition($i, $r->user(), $d['status'], $d['note'] ?? ($d['status'] === 'done' ? 'Marked as done.' : 'Marked as undone.'));
        });

        return new IncidentResource($incident->fresh());
    }

    public function verify(Request $r, Incident $incident, IncidentWorkflow $flow)
    {
        Gate::authorize('update', $incident);
        $d = $r->validate(['rationale' => 'required|string|max:2000']);
        DB::transaction(function () use ($r, $incident, $flow, $d) {
            $i = Incident::lockForUpdate()->findOrFail($incident->id);
            Gate::authorize('update', $i);
            abort_if($i->reporter_id === $r->user()->id, 403);
            $before = $i->verification_status;
            $i->update(['verification_status' => 'validated']);
            IncidentVerification::create(['incident_id' => $i->id, 'verified_by' => $r->user()->id, 'outcome' => 'validated', 'rationale' => $d['rationale']]);
            AuditLog::recordChange($r->user(), 'incident.verified', $i, ['verification_status' => $before], ['verification_status' => 'validated'], $i->public_reference);
            $flow->notify($i);
        });

        return new IncidentResource($incident->fresh());
    }

    public function response(Request $r, Incident $incident)
    {
        Gate::authorize('update', $incident);
        $d = $r->validate(['note' => 'required|string|max:2000', 'outcome' => 'nullable|string|max:255', 'occurred_at' => 'required|date|before_or_equal:now']);
        $response = DB::transaction(function () use ($r, $incident, $d) {
            $i = Incident::lockForUpdate()->findOrFail($incident->id);
            Gate::authorize('update', $i);

            return IncidentResponse::create($d + ['incident_id' => $i->id, 'officer_id' => $r->user()->id]);
        });

        return response()->json(['data' => $response], 201);
    }

    public function assign(Request $r, Incident $incident)
    {
        Gate::authorize('update', $incident);
        $d = $r->validate(['assigned_officer_id' => 'nullable|integer|exists:users,id', 'agency_id' => 'sometimes|nullable|integer|exists:agencies,id', 'note' => 'required|string|max:2000']);
        DB::transaction(function () use ($r, $incident, $d) {
            $i = Incident::lockForUpdate()->findOrFail($incident->id);
            Gate::authorize('update', $i);
            $before = ['agency' => Agency::find($i->agency_id)?->name, 'assigned_officer' => User::find($i->assigned_officer_id)?->name];
            if (array_key_exists('agency_id', $d)) {
                abort_unless($r->user()->role === 'admin', 403);
                $i->agency_id = $d['agency_id'];
                $i->assigned_officer_id = null;
            }
            if (! empty($d['assigned_officer_id'])) {
                abort_unless(User::whereKey($d['assigned_officer_id'])->where('active', true)->where('role', 'officer')->where('agency_id', $i->agency_id)->exists(), 422, 'Choose an active officer in the assigned agency.');
            }
            $i->assigned_officer_id = $d['assigned_officer_id'] ?? null;
            $i->save();
            AuditLog::recordChange($r->user(), 'incident.assignment', $i, $before, ['agency' => Agency::find($i->agency_id)?->name, 'assigned_officer' => User::find($i->assigned_officer_id)?->name, 'note' => $d['note']], $i->public_reference);
        });

        return new IncidentResource($incident->fresh());
    }
}
