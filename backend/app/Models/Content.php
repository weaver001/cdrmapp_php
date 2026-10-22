<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Content extends Model
{
    protected $guarded = [];

    use SoftDeletes;

    protected $casts = ['published' => 'boolean', 'details' => 'array'];

    protected $appends = ['image_url'];

    public function getImageUrlAttribute(): ?string
    {
        if (! $this->image_path) {
            return null;
        }

        $disk = $this->image_disk ?: 'public';
        $storage = Storage::disk($disk);

        // Cloud buckets remain private, including uploads to unpublished posts.
        return config("filesystems.disks.{$disk}.driver") === 's3'
            ? $storage->temporaryUrl($this->image_path, now()->addMinutes(30))
            : $storage->url($this->image_path);
    }
}
