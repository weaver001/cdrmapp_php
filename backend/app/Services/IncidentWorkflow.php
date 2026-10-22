<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\IncidentStatusHistory;
use App\Models\User;
use App\Notifications\IncidentChanged;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

class IncidentWorkflow
{
    public const TRANSITIONS = ['submitted' => ['received', 'rejected', 'duplicate'], 'received' => ['verified', 'rejected', 'duplicate'], 'verified' => ['in_progress', 'rejected', 'duplicate'], 'in_progress' => ['resolved'], 'resolved' => ['closed'], 'closed' => [], 'rejected' => [], 'duplicate' => []];

    public function transition(Incident $i, User $actor, string $next, ?string $note): void
    {
        if (! in_array($next, self::TRANSITIONS[$i->workflow_status] ?? [], true)) {
            throw ValidationException::withMessages(['status' => 'This status transition is not allowed.']);
        }
        $old = $i->workflow_status;
        $i->workflow_status = $next;
        if ($next === 'verified') {
            $i->verification_status = 'validated';
        }
        if (in_array($next, ['rejected', 'duplicate'])) {
            $i->verification_status = $next;
        }
        $i->save();
        IncidentStatusHistory::create(['incident_id' => $i->id, 'actor_id' => $actor->id, 'old_status' => $old, 'new_status' => $next, 'note' => $note]);
        AuditLog::recordChange($actor, 'incident.status', $i, ['status' => $old], ['status' => $next], $i->public_reference);
        $this->notify($i);
    }

    public function notify(Incident $i): void
    {
        $users = User::where('active', true)->where(function ($q) use ($i) {
            $q->where('role', 'admin')->orWhere('id', $i->reporter_id);
            if ($i->agency_id) {
                $q->orWhere(fn ($q) => $q->where('role', 'officer')->where('agency_id', $i->agency_id));
            }
        })->get();
        Notification::send($users, new IncidentChanged($i->id, $i->public_reference, $i->workflow_status));
    }
}
