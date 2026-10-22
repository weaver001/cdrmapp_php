<?php

namespace App\Policies;

use App\Models\Incident;
use App\Models\User;

class IncidentPolicy
{
    public function view(User $u, Incident $i): bool
    {
        return $u->active && ($u->role === 'admin' || ($u->role === 'officer' && $u->agency_id !== null && $u->agency_id === $i->agency_id) || ($u->role === 'citizen' && $u->id === $i->reporter_id));
    }

    public function update(User $u, Incident $i): bool
    {
        return $u->isStaff() && $this->view($u, $i);
    }
}
