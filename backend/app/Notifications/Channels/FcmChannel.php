<?php

namespace App\Notifications\Channels;

use App\Notifications\IncidentChanged;
use App\Services\Fcm;

class FcmChannel
{
    public function send(object $notifiable, IncidentChanged $notification): void
    {
        app(Fcm::class)->send($notifiable, $notification->toArray($notifiable));
    }
}
