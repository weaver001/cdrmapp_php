<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class Incident extends Model
{
    public const DONE_STATUSES = ['done', 'resolved', 'closed', 'rejected', 'duplicate'];

    protected $guarded = [];

    protected $attributes = ['workflow_status' => 'need_action', 'verification_status' => 'unverified', 'is_test' => false];

    protected $hidden = ['request_hash', 'idempotency_key'];

    protected $casts = ['received_at' => 'datetime', 'incident_at' => 'datetime', 'location_observed_at' => 'datetime', 'latitude' => 'float', 'longitude' => 'float', 'accuracy_m' => 'float', 'is_test' => 'boolean'];

    public function getWorkflowStatusAttribute(?string $value): string
    {
        return in_array($value, self::DONE_STATUSES, true) ? 'done' : 'need_action';
    }

    public function scopeReportStatus(Builder $query, string $status): Builder
    {
        return $status === 'done'
            ? $query->whereIn('workflow_status', self::DONE_STATUSES)
            : $query->whereNotIn('workflow_status', self::DONE_STATUSES);
    }

    protected static function booted(): void
    {
        static::saved(function () {
            DB::afterCommit(function () {
                Cache::add('analytics-version', 0, 86400);
                Cache::increment('analytics-version');
            });
        });
    }

    public function scopeVisibleTo(Builder $q, User $u): Builder
    {
        if ($u->role === 'admin') {
            return $q;
        }
        if ($u->role === 'officer') {
            return $q->where('agency_id', $u->agency_id ?? -1);
        }

        return $q->where('reporter_id', $u->id);
    }

    public function reporter()
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function agency()
    {
        return $this->belongsTo(Agency::class);
    }

    public function category()
    {
        return $this->belongsTo(IncidentCategory::class, 'category_id');
    }

    public function histories()
    {
        return $this->hasMany(IncidentStatusHistory::class);
    }

    public function responses()
    {
        return $this->hasMany(IncidentResponse::class);
    }

    public function attachments()
    {
        return $this->hasMany(IncidentAttachment::class);
    }
}
