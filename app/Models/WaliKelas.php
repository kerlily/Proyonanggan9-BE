<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Traits\LogsActivity;

class WaliKelas extends Model
{
    use HasFactory, LogsActivity;
    protected static $logAttributes = ['guru_id', 'kelas_id', 'tahun_ajaran_id'];
    protected static $logName = 'wali_kelas';
    protected $table = 'wali_kelas';

    protected $fillable = [
    'guru_id',
    'kelas_id',
    'tahun_ajaran_id',
    'is_primary',
    ];

    protected $casts = [
    'is_primary' => 'boolean',
    ];


    public function guru()
    {
        return $this->belongsTo(Guru::class, 'guru_id');
    }

    public function kelas()
    {
        return $this->belongsTo(Kelas::class, 'kelas_id');
    }

    public function tahunAjaran()
    {
        return $this->belongsTo(TahunAjaran::class, 'tahun_ajaran_id');
    }
}
