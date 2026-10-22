<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AuditLog extends Model
{
    protected $guarded = [];

    protected $casts = ['metadata' => 'array'];

    protected $appends = ['actor_name', 'record_label'];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function getActorNameAttribute(): string
    {
        return $this->metadata['actor']['name'] ?? $this->actor?->name ?? 'Unknown user';
    }

    public function getRecordLabelAttribute(): string
    {
        return $this->metadata['record']['label'] ?? ucfirst(str_replace('_', ' ', $this->record_type)).' #'.$this->record_id;
    }

    public static function recordChange(User $actor, string $action, Model $record, array $before, array $after, ?string $label = null): self
    {
        // Callers supply an explicit field allowlist: never serialize a whole user,
        // credentials, private evidence, or temporary signed image URLs into audit.
        return self::create([
            'actor_id' => $actor->id, 'action' => $action,
            'record_type' => Str::snake(class_basename($record)), 'record_id' => $record->getKey(),
            'metadata' => [
                'actor' => ['name' => $actor->name, 'email' => $actor->email],
                'record' => ['label' => $label ?? $record->getAttribute('title') ?? $record->getAttribute('name') ?? class_basename($record).' #'.$record->getKey()],
                'before' => $before, 'after' => $after,
            ],
        ]);
    }
}
