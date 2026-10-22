<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IncidentCategory extends Model
{
    protected $guarded = [];

    protected $casts = ['active' => 'boolean'];
}
