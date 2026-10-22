<?php

namespace App\Notifications;

use App\Notifications\Channels\FcmChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class IncidentChanged extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $incidentId, public string $reference, public string $status)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return config('services.fcm.enabled') ? ['database', FcmChannel::class] : ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['incident_id' => $this->incidentId, 'public_reference' => $this->reference, 'status' => $this->status];
    }
}
