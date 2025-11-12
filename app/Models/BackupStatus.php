<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class BackupStatus extends Model
{
    use HasFactory;

    protected $table = 'backup_status';

    protected $fillable = [
        'filename',
        'disk',
        'size',
        'backup_date',
        'status',
        'error_message',
    ];

    protected $casts = [
        'backup_date' => 'datetime',
        'size' => 'integer',
    ];

    public function getSizeHumanAttribute()
    {
        $bytes = $this->size;
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }
}
