<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Traits\LogsActivity;

class NilaiSikap extends Model
{
    use HasFactory, LogsActivity;

    protected $table = 'nilai_sikap';

    protected static $logAttributes = ['siswa_id', 'semester_id', 'tahun_ajaran_id', 'nilai', 'deskripsi'];
    protected static $logName = 'nilai_sikap';

    protected $fillable = [
        'siswa_id',
        'semester_id',
        'tahun_ajaran_id',
        'nilai',
        'deskripsi',
        'input_by_guru_id',
    ];

    protected $casts = [
        'nilai' => 'string',
    ];

    // Relationships
    public function siswa()
    {
        return $this->belongsTo(Siswa::class, 'siswa_id');
    }

    public function semester()
    {
        return $this->belongsTo(Semester::class, 'semester_id');
    }

    public function tahunAjaran()
    {
        return $this->belongsTo(TahunAjaran::class, 'tahun_ajaran_id');
    }

    public function inputByGuru()
    {
        return $this->belongsTo(Guru::class, 'input_by_guru_id');
    }

    // Helper: Get label nilai sikap
    public function getNilaiLabelAttribute()
    {
        $labels = [
            'A' => 'Sangat Baik',
            'B' => 'Baik',
            'C' => 'Cukup',
            'D' => 'Kurang',
            'E' => 'Sangat Kurang',
        ];

        return $labels[$this->nilai] ?? '-';
    }
}
