<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgencyDispatchRoute extends Model
{
    protected $guarded = [];

    protected $casts = ['active' => 'boolean'];

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }
}
