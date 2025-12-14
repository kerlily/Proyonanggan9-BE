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

    // ✅ DISABLED: LogsActivity untuk performa login
    // use LogsActivity;

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
    ];

    // ✅ CRITICAL: JANGAN auto-load relasi
    // protected $with = ['kelas']; // ❌ NEVER DO THIS

    /**
     * ✅ OPTIMIZED: Password hashing
     */
    public function setPasswordAttribute($value)
    {
        if ($value === null) return;

        // Skip rehash jika sudah bcrypt format
        if (strlen($value) === 60 && str_starts_with($value, '$2y$')) {
            $this->attributes['password'] = $value;
            return;
        }

        $this->attributes['password'] = Hash::make($value);
    }

    // JWTSubject
    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        // ✅ Minimal claims = JWT token lebih kecil = lebih cepat
        return [
            'role' => 'siswa',
            'kelas_id' => $this->kelas_id,
        ];
    }

    /**
     * ✅ Relations - Lazy loading only
     */
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

    /**
     * ✅ Helper method tanpa cache
     */
    public function getKelasForTahunAjaran($tahunAjaranId)
    {
        return $this->riwayatKelas()
            ->with('kelas:id,nama,tingkat,section')
            ->where('tahun_ajaran_id', $tahunAjaranId)
            ->first()?->kelas;
    }

    /**
     * ✅ DISABLE activity logging untuk performa
     */
    protected static function boot()
    {
        parent::boot();

        // ✅ Logging disabled untuk shared hosting performa
        // Uncomment jika butuh logging tapi akan slow down login

        // static::created(function ($siswa) {
        //     activity()->performedOn($siswa)->log('created');
        // });
    }
}
