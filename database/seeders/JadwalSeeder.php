<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class JadwalSeeder extends Seeder
{
    public function run(): void
    {
        $kelas = DB::table('kelas')->limit(4)->get(); // ambil beberapa kelas contoh
        $firstGuru = DB::table('guru')->first();
        $firstSemester = DB::table('semester')->first();

        if ($kelas->isEmpty() || ! $firstGuru) {
            // jika tidak ada data kelas/guru, skip
            return;
        }

        foreach ($kelas as $k) {
            DB::table('jadwals')->insert([
                'kelas_id' => $k->id,
                'guru_id' => $firstGuru->id,
                'semester_id' => $firstSemester ? $firstSemester->id : null,
                'image' => 'jadwals/jadwal_' . strtolower(Str::slug($k->nama)) . '.png',
                'title' => 'Jadwal ' . $k->nama,
                'description' => 'Jadwal pelajaran untuk kelas ' . $k->nama,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
