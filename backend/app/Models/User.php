<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $attributes = ['role' => 'citizen', 'active' => true, 'must_change_password' => false];

    protected $fillable = ['name', 'email', 'password', 'date_of_birth', 'phone'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'password' => 'hashed', 'active' => 'boolean', 'must_change_password' => 'boolean', 'date_of_birth' => 'date:Y-m-d'];
    }

    public function agency()
    {
        return $this->belongsTo(Agency::class);
    }

    public function isStaff(): bool
    {
        return $this->active && in_array($this->role, ['admin', 'officer'], true);
    }

    public function hasVerifiedEmail(): bool
    {
        // Staff identities are approved by an administrator, not by email domain.
        // Keep the timestamp as actual ownership verification for citizen accounts.
        return in_array($this->role, ['admin', 'officer'], true) || $this->email_verified_at !== null;
    }

    public function getProfilePhotoUrlAttribute(): ?string
    {
        return $this->profile_photo_path
            ? '/api/v1/me/photo?v='.hash('sha256', $this->profile_photo_path)
            : null;
    }
}
