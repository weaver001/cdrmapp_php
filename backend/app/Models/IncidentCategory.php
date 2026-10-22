<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class IncidentCategory extends Model
{
    protected $guarded = [];

    protected $casts = ['active' => 'boolean'];

    public function scopeInDisplayOrder(Builder $query): Builder
    {
        return $query->orderByRaw('CASE name WHEN ? THEN 0 WHEN ? THEN 1 WHEN ? THEN 2 ELSE 3 END', array_values(Agency::DEFAULT_NAMES))->orderBy('name');
    }
}
