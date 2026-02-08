<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Storage;

class Berita extends Model
{
    use HasFactory;

    protected $table = 'beritas';

    protected $fillable = [
        'type',
        'title',
        'description',
        'image',
        'created_by',
        'is_published',
        'published_at',
    ];

    protected $casts = [
        'is_published' => 'boolean',
        'published_at' => 'datetime',
    ];

    // tambahkan image_url ke JSON
    protected $appends = ['image_url'];

    // sembunyikan path mentah jika mau (opsional tapi direkomendasikan)
    protected $hidden = [
        'image',
        'created_by',
    ];

    public function scopePengumuman($query)
    {
        return $query->where('type', 'pengumuman');
    }

    public function scopeBerita($query)
    {
        return $query->where('type', 'berita');
    }

    public function author()
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    public function getImageUrlAttribute()
    {
        if (! $this->image) return null;

        // cara yang sama dengan Gallery: pakai url('storage/...') → menghasilkan URL absolut
        return url('storage/' . ltrim($this->image, '/'));

        // alternatif lain:
        // return asset(Storage::url($this->image));
    }
}
