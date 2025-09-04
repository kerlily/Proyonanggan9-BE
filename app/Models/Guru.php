<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Guru extends Model
{
    use HasFactory;

    protected $table = 'guru';

    protected $fillable = [
        'user_id',
        'nama',
        'nip',
        'no_hp',
    ];

    // relations
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function waliKelasAssignments()
    {
        return $this->hasMany(WaliKelas::class, 'guru_id');
    }

    public function nilaiInput()
    {
        return $this->hasMany(Nilai::class, 'input_by_guru_id');
    }
}
