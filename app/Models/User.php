<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Tymon\JWTAuth\Contracts\JWTSubject;
use Illuminate\Support\Facades\Hash;
use App\Traits\LogsActivity;

class User extends Authenticatable implements JWTSubject
{
    use HasFactory, Notifiable, LogsActivity;

    protected static $logAttributes = ['name', 'email', 'role'];
    protected static $logName = 'users';
    protected $table = 'users';

    protected $fillable = [
        'name',
        'email',
        'password',
        'role', // admin | guru
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    // Hash password automatically
    public function setPasswordAttribute($value)
    {
        if ($value === null) return;
        $this->attributes['password'] = Hash::needsRehash($value) ? Hash::make($value) : $value;
    }

    // JWTSubject
    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [];
    }

    // helpers
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isGuru(): bool
    {
        return $this->role === 'guru';
    }

    // relations
    public function guru()
    {
        return $this->hasOne(Guru::class, 'user_id');
    }
}
