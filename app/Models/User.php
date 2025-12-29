<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Tymon\JWTAuth\Contracts\JWTSubject;
use Illuminate\Support\Facades\Hash;
use App\Traits\LogsActivity;

class User extends Authenticatable implements JWTSubject
{
    use HasFactory, Notifiable, LogsActivity, SoftDeletes;

    protected static $logAttributes = ['name', 'email', 'role'];
    protected static $logName = 'users';

    protected $table = 'users';

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'deleted_at' => 'datetime',
    ];

    public function setPasswordAttribute($value)
    {
        if ($value === null) return;
        $this->attributes['password'] = Hash::needsRehash($value) ? Hash::make($value) : $value;
    }

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [];
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isGuru(): bool
    {
        return $this->role === 'guru';
    }

    public function guru()
    {
        return $this->hasOne(Guru::class, 'user_id');
    }
    public function isDeleted(): bool
    {
        return $this->trashed();
    }
}
