<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\LogsActivity;

class Nilai extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected static $logAttributes = ['siswa_id', 'mapel_id', 'semester_id', 'tahun_ajaran_id', 'nilai', 'catatan'];
    protected static $logName = 'nilai';

    protected $table = 'nilai';

    protected $fillable = [
        'siswa_id',
        'mapel_id',
        'semester_id',
        'tahun_ajaran_id',
        'nilai',
        'catatan',
        'input_by_guru_id',
    ];

    protected $casts = [
        'deleted_at' => 'datetime',
    ];

    public function siswa()
    {
        return $this->belongsTo(Siswa::class, 'siswa_id');
    }

    public function mapel()
    {
        return $this->belongsTo(Mapel::class, 'mapel_id');
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

    public function kelas()
    {
        return $this->belongsTo(Kelas::class, 'kelas_id');
    }
}
