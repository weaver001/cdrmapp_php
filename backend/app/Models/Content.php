<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Content extends Model
{
    protected $guarded = [];

    use SoftDeletes;

    protected $casts = ['published' => 'boolean', 'details' => 'array'];
}
