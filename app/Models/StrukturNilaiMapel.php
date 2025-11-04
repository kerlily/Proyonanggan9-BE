<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class StrukturNilaiMapel extends Model
{
    use HasFactory;

    protected $table = 'struktur_nilai_mapel';

    protected $fillable = [
        'mapel_id',
        'kelas_id',
        'semester_id',
        'tahun_ajaran_id',
        'created_by_guru_id',
        'struktur',
    ];

    protected $casts = [
        'struktur' => 'array',
    ];

    public function mapel()
    {
        return $this->belongsTo(Mapel::class);
    }

    public function kelas()
    {
        return $this->belongsTo(Kelas::class);
    }

    public function semester()
    {
        return $this->belongsTo(Semester::class);
    }

    public function tahunAjaran()
    {
        return $this->belongsTo(TahunAjaran::class);
    }

    public function createdByGuru()
    {
        return $this->belongsTo(Guru::class, 'created_by_guru_id');
    }

    public function nilaiDetails()
    {
        return $this->hasMany(NilaiDetail::class);
    }
}
