<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Semester extends Model
{
    use HasFactory;

    protected $table = 'semester';

    protected $fillable = [
        'tahun_ajaran_id',
        'nama', // ganjil | genap
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function tahunAjaran()
    {
        return $this->belongsTo(TahunAjaran::class, 'tahun_ajaran_id');
    }

    public function nilai()
    {
        return $this->hasMany(Nilai::class, 'semester_id');
    }
}
