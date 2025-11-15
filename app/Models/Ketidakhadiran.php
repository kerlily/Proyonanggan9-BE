<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Traits\LogsActivity;

class Ketidakhadiran extends Model
{
    use HasFactory, LogsActivity;

    protected $table = 'ketidakhadiran';

    protected static $logAttributes = ['siswa_id', 'semester_id', 'tahun_ajaran_id', 'ijin', 'sakit', 'alpa'];
    protected static $logName = 'ketidakhadiran';

    protected $fillable = [
        'siswa_id',
        'semester_id',
        'tahun_ajaran_id',
        'ijin',
        'sakit',
        'alpa',
        'catatan',
        'input_by_guru_id',
    ];

    protected $casts = [
        'ijin' => 'integer',
        'sakit' => 'integer',
        'alpa' => 'integer',
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

    // Helper: Get total ketidakhadiran
    public function getTotalAttribute()
    {
        return $this->ijin + $this->sakit + $this->alpa;
    }
}
