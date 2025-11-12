<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Traits\LogsActivity;
class Guru extends Model
{
    use HasFactory, LogsActivity;
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

    // tambahkan accessor otomatis muncul di JSON
    protected $appends = ['photo_url'];

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

    /**
     * Accessor: getPhotoUrlAttribute
     * FIXED: Pattern PERSIS seperti PublicGuruController
     */
    public function getPhotoUrlAttribute()
    {
        if (!$this->photo) {
            return null;
        }

        // Jika sudah absolute URL, return as is
        if (preg_match('/^https?:\\/\\//i', $this->photo)) {
            return $this->photo;
        }

        // FIXED: Pattern SAMA dengan PublicGuruController
        return url('storage/'.$this->photo);
    }
}
