<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Traits\LogsActivity;

class Mapel extends Model
{
    use HasFactory, LogsActivity;
    protected static $logAttributes = ['nama', 'kode'];
    protected static $logName = 'mapel';
    protected $table = 'mapel';

    protected $fillable = [
        'nama',
        'kode',
    ];

    public function nilai()
    {
        return $this->hasMany(Nilai::class, 'mapel_id');
    }

    public function kelas()
{
    return $this->belongsToMany(Kelas::class, 'kelas_mapel', 'mapel_id', 'kelas_id')
                ->withTimestamps();
}
}
