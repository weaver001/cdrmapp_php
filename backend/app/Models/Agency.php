<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Agency extends Model
{
    protected $guarded = [];

    protected $casts = ['active' => 'boolean'];
}
