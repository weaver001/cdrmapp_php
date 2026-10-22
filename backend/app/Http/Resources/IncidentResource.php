<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class IncidentResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => $this->id, 'public_reference' => $this->public_reference, 'description' => $this->description, 'agency_id' => $this->agency_id, 'category_id' => $this->category_id, 'agency' => $this->whenLoaded('agency'), 'category' => $this->whenLoaded('category'), 'latitude' => $this->latitude, 'longitude' => $this->longitude, 'accuracy_m' => $this->accuracy_m, 'location_source' => $this->location_source, 'location_observed_at' => $this->location_observed_at?->toISOString(), 'address' => $this->address, 'received_at' => $this->received_at?->toISOString(), 'workflow_status' => $this->workflow_status, 'verification_status' => $this->verification_status, 'assigned_officer_id' => $this->assigned_officer_id, 'needs_triage' => $this->agency_id === null, 'is_test' => $this->is_test, 'histories' => $this->whenLoaded('histories'), 'responses' => $this->whenLoaded('responses'), 'attachments' => $this->whenLoaded('attachments', fn () => $this->attachments->map(fn ($a) => ['id' => $a->id, 'mime' => $a->mime, 'size' => $a->size])), 'reporter' => $this->when($request->user()?->isStaff() && $this->relationLoaded('reporter'), fn () => ['id' => $this->reporter->id, 'name' => $this->reporter->name])];
    }
}
