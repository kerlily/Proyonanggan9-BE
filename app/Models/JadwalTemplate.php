<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class JadwalTemplate extends Model
{
    use HasFactory;

    protected $table = 'jadwal_templates';

    protected $fillable = [
        'kelas_id',
        'semester_id',
        'tahun_ajaran_id',
        'nama',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function kelas()
    {
        return $this->belongsTo(Kelas::class, 'kelas_id');
    }

    public function semester()
    {
        return $this->belongsTo(Semester::class, 'semester_id');
    }

    public function tahunAjaran()
    {
        return $this->belongsTo(TahunAjaran::class, 'tahun_ajaran_id');
    }

    public function slots()
    {
        return $this->hasMany(JadwalSlot::class, 'jadwal_template_id')
                    ->orderBy('urutan');
    }

    // Helper: get slots grouped by hari
    public function slotsByHari()
    {
        return $this->slots()
                    ->get()
                    ->groupBy('hari');
    }
}
