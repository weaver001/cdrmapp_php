<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class Incident extends Model
{
    protected $guarded = [];

    protected $attributes = ['workflow_status' => 'submitted', 'verification_status' => 'unverified', 'is_test' => false];

    protected $hidden = ['request_hash', 'idempotency_key'];

    protected $casts = ['received_at' => 'datetime', 'incident_at' => 'datetime', 'location_observed_at' => 'datetime', 'latitude' => 'float', 'longitude' => 'float', 'accuracy_m' => 'float', 'is_test' => 'boolean'];

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
