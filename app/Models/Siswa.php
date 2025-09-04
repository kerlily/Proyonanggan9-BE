<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Tymon\JWTAuth\Contracts\JWTSubject;
use Illuminate\Support\Facades\Hash;

class Siswa extends Authenticatable implements JWTSubject
{
    use HasFactory, Notifiable;

    protected $table = 'siswa';

    protected $fillable = [
        'nama',
        'tahun_lahir',
        'password',
        'kelas_id',
        'is_alumni',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'is_alumni' => 'boolean',
        'tahun_lahir' => 'integer',
    ];

    // auto-hash student password (default is tahun_lahir)
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

    // relations
    public function kelas()
    {
        return $this->belongsTo(Kelas::class, 'kelas_id');
    }

    public function riwayatKelas()
    {
        return $this->hasMany(RiwayatKelas::class, 'siswa_id');
    }

    public function nilai()
    {
        return $this->hasMany(Nilai::class, 'siswa_id');
    }
}
