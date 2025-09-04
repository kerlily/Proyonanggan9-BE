<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Mapel extends Model
{
    use HasFactory;

    protected $table = 'mapel';

    protected $fillable = [
        'nama',
        'kode',
    ];

    public function nilai()
    {
        return $this->hasMany(Nilai::class, 'mapel_id');
    }
}
