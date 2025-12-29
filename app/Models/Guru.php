<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes; // ✅ TAMBAH INI
use App\Traits\LogsActivity;

class Guru extends Model
{
    use HasFactory, LogsActivity, SoftDeletes; // ✅ TAMBAH SoftDeletes

    protected static $logAttributes = ['nama', 'nip', 'no_hp'];
    protected static $logName = 'guru';

    protected $table = 'guru';

    protected $fillable = [
        'user_id',
        'nama',
        'nip',
        'no_hp',
        'photo',
    ];

    protected $appends = ['photo_url'];

    // ✅ TAMBAH: Cast deleted_at
    protected $casts = [
        'deleted_at' => 'datetime',
    ];

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

    public function getPhotoUrlAttribute()
    {
        if (!$this->photo) {
            return null;
        }

        if (preg_match('/^https?:\\/\\//i', $this->photo)) {
            return $this->photo;
        }

        return url('storage/'.$this->photo);
    }

    // ✅ TAMBAH: Helper untuk cek status
    public function isDeleted(): bool
    {
        return $this->trashed();
    }
}
