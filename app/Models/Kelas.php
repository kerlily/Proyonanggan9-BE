<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes; // ✅ TAMBAH INI

class Kelas extends Model
{
    use HasFactory, SoftDeletes; // ✅ TAMBAH SoftDeletes

    protected $table = 'kelas';

    protected $fillable = [
        'nama',
        'tingkat',
        'section',
    ];

    // ✅ TAMBAH: Cast deleted_at
    protected $casts = [
        'deleted_at' => 'datetime',
    ];

    public function siswa()
    {
        return $this->hasMany(Siswa::class, 'kelas_id');
    }

    public function waliKelasAssignments()
    {
        return $this->hasMany(WaliKelas::class, 'kelas_id');
    }

    public function currentWali()
    {
        $tahun = TahunAjaran::where('is_active', true)->first();
        if (!$tahun) return null;
        return $this->waliKelasAssignments()
            ->where('tahun_ajaran_id', $tahun->id)
            ->with('guru')
            ->first();
    }

    public function mapels()
    {
        return $this->belongsToMany(Mapel::class, 'kelas_mapel', 'kelas_id', 'mapel_id')
                    ->withTimestamps();
    }

    // ✅ TAMBAH: Helper
    public function isDeleted(): bool
    {
        return $this->trashed();
    }
}
