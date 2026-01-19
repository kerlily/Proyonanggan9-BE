<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class CatatanMapelSiswa extends Model
{
    use HasFactory;

    protected $table = 'catatan_mapel_siswa';

    protected $fillable = [
        'siswa_id',
        'struktur_nilai_mapel_id',
        'catatan',
        'input_by_guru_id',
    ];

    public function siswa()
    {
        return $this->belongsTo(Siswa::class);
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
