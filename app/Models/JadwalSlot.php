<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class JadwalSlot extends Model
{
    use HasFactory;

    protected $table = 'jadwal_slots';

    protected $fillable = [
        'jadwal_template_id',
        'hari',
        'jam_mulai',
        'jam_selesai',
        'tipe_slot',
        'mapel_id',
        'keterangan',
        'urutan',
    ];

    protected $casts = [
        'jam_mulai' => 'datetime:H:i',
        'jam_selesai' => 'datetime:H:i',
    ];

    public function jadwalTemplate()
    {
        return $this->belongsTo(JadwalTemplate::class, 'jadwal_template_id');
    }

    public function mapel()
    {
        return $this->belongsTo(Mapel::class, 'mapel_id');
    }

    // Accessor: durasi dalam menit
    public function getDurasiMenitAttribute()
    {
        if (!$this->jam_mulai || !$this->jam_selesai) {
            return 0;
        }

        $mulai = \Carbon\Carbon::parse($this->jam_mulai);
        $selesai = \Carbon\Carbon::parse($this->jam_selesai);

        return $selesai->diffInMinutes($mulai);
    }

    protected $appends = ['durasi_menit'];
}
