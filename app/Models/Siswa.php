<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Tymon\JWTAuth\Contracts\JWTSubject;
use Illuminate\Support\Facades\Hash;

class Siswa extends Authenticatable implements JWTSubject
{
    use HasFactory, Notifiable, SoftDeletes;

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
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'is_alumni' => 'boolean',
        'tahun_lahir' => 'integer',
        'deleted_at' => 'datetime', // ✅ TAMBAH INI
    ];

    public function setPasswordAttribute($value)
    {
        if ($value === null) return;

        if (strlen($value) === 60 && str_starts_with($value, '$2y$')) {
            $this->attributes['password'] = $value;
            return;
        }

        $this->attributes['password'] = Hash::make($value);
    }

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [
            'role' => 'siswa',
            'kelas_id' => $this->kelas_id,
        ];
    }

    public function kelas()
    {
        return $this->belongsTo(Kelas::class);
    }

    public function nilai()
    {
        return $this->hasMany(Nilai::class);
    }

    public function riwayatKelas()
    {
        return $this->hasMany(RiwayatKelas::class);
    }

    public function isDeleted(): bool
    {
        return $this->trashed();
    }

    public function getKelasForTahunAjaran($tahunAjaranId)
    {
        return $this->riwayatKelas()
            ->with('kelas:id,nama,tingkat,section')
            ->where('tahun_ajaran_id', $tahunAjaranId)
            ->first()?->kelas;
    }
}
