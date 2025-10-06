<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Kelas extends Model
{
    use HasFactory;

    protected $table = 'kelas';

    protected $fillable = [
        'nama',
        'tingkat', // 1..6
        'section', // A/B
    ];

    // relations
    public function siswa()
    {
        return $this->hasMany(Siswa::class, 'kelas_id');
    }

    public function waliKelasAssignments()
    {
        return $this->hasMany(WaliKelas::class, 'kelas_id');
    }

    // convenience helper to get current wali for an active year
    public function currentWali()
    {
        $tahun = TahunAjaran::where('is_active', true)->first();
        if (! $tahun) return null;
        return $this->waliKelasAssignments()->where('tahun_ajaran_id', $tahun->id)->with('guru')->first();
    }
    public function mapels()
{
    return $this->belongsToMany(Mapel::class, 'kelas_mapel', 'kelas_id', 'mapel_id')
                ->withTimestamps();
}
}
