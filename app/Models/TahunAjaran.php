<?php
namespace App\Models;


use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class TahunAjaran extends Model
{
    use HasFactory;

    protected $table = 'tahun_ajaran';

    protected $fillable = [
        'nama',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function semesters()
    {
        return $this->hasMany(Semester::class, 'tahun_ajaran_id');
    }

    public function waliKelasAssignments()
    {
        return $this->hasMany(WaliKelas::class, 'tahun_ajaran_id');
    }

    public function riwayatKelas()
    {
        return $this->hasMany(RiwayatKelas::class, 'tahun_ajaran_id');
    }
}
