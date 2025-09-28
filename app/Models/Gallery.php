<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Gallery extends Model
{
    use HasFactory;

    protected $table = 'galleries';

    protected $fillable = [
        'image',
        'created_by',
    ];

    // append full URL for convenience
    protected $appends = ['image_url'];

    public function getImageUrlAttribute()
    {
        if (! $this->image) return null;
        return url('storage/' . ltrim($this->image, '/'));
    }

    // Hide raw image path in JSON if you want only image_url exposed
    protected $hidden = [
        'image',
        'created_by',
    ];
}
