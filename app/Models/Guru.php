<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Storage;

class Guru extends Model
{
    use HasFactory;

    protected $table = 'guru';

    // tambahkan 'photo' ke fillable
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
     * Mengembalikan URL publik untuk photo, mencoba beberapa strategi:
     * - kalau sudah absolute URL -> kembalikan apa adanya
     * - kalau sudah mulai dengan 'storage/' -> url(...)
     * - kalau disimpan di disk 'public' -> Storage::disk('public')->url(...)
     * - fallback -> url('storage/guru_photos/<path>')
     */
    public function getPhotoUrlAttribute()
    {
        $photo = $this->photo;

        if (!$photo) {
            return null;
        }

        // already absolute
        if (preg_match('/^https?:\\/\\//i', $photo)) {
            return $photo;
        }

        // already starts with storage/
        if (str_starts_with($photo, 'storage/') || str_starts_with($photo, '/storage/')) {
            return url($photo);
        }

        // try Storage disk 'public' (most common when using ->store('guru_photos','public'))
        try {
            $url = Storage::disk('public')->url($photo);
            if ($url) {
                return $url;
            }
        } catch (\Throwable $e) {
            // ignore and fallback
        }

        // fallback: assume folder storage/app/public/guru_photos
        return url('storage/' . ltrim($photo, '/'));
    }
}
