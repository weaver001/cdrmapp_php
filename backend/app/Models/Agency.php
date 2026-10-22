<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Agency extends Model
{
    // Keep existing type identifiers so permissions, dispatch rules and clients remain compatible.
    public const DEFAULT_NAMES = [
        'FIRE' => 'Fire Department (BFP)',
        'PNP' => 'Police Department (PNP)',
        'MDRRMO' => 'MDRRMO',
    ];

    public const LEGACY_CATEGORY_NAMES = [
        'FIRE' => ['Fire Department', 'Fire Department (BFP)'],
        'PNP' => ['Police / Crime', 'Police Department (PNP)'],
        'MDRRMO' => ['Disaster and Emergency', 'MDRRMO'],
    ];

    protected $guarded = [];

    protected $casts = ['active' => 'boolean'];

    public function scopeInDisplayOrder(Builder $query): Builder
    {
        return $query->orderByRaw("CASE type WHEN 'FIRE' THEN 0 WHEN 'PNP' THEN 1 WHEN 'MDRRMO' THEN 2 ELSE 3 END")->orderBy('name');
    }
}
