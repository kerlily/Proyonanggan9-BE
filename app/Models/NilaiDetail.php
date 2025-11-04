<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class NilaiDetail extends Model
{
    use HasFactory;

    protected $table = 'nilai_detail';

    protected $fillable = [
        'siswa_id',
        'mapel_id',
        'semester_id',
        'tahun_ajaran_id',
        'struktur_nilai_mapel_id',
        'lm_key',
        'kolom_key',
        'nilai',
        'input_by_guru_id',
    ];

    protected $casts = [
        'nilai' => 'decimal:2',
    ];

    public function siswa()
    {
        return $this->belongsTo(Siswa::class);
    }

    public function mapel()
    {
        return $this->belongsTo(Mapel::class);
    }

    public function semester()
    {
        return $this->belongsTo(Semester::class);
    }

    public function tahunAjaran()
    {
        return $this->belongsTo(TahunAjaran::class);
    }

    public function strukturNilaiMapel()
    {
        return $this->belongsTo(StrukturNilaiMapel::class);
    }

    public function inputByGuru()
    {
        return $this->belongsTo(Guru::class, 'input_by_guru_id');
    }
}
