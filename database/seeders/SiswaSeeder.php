<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class SiswaSeeder extends Seeder
{
    public function run(): void
    {
        $kelas = DB::table('kelas')->get();

        // create 5 siswa per kelas for first 3 kelas only to keep seed small
        foreach ($kelas as $k) {
            for ($i=1; $i<=3; $i++) {
                $tahun_lahir = 2016 - ($k->tingkat - 1); // approximate
                DB::table('siswa')->insert([
                    'nama' => 'Siswa ' . $k->nama . ' ' . $i,
                    'tahun_lahir' => $tahun_lahir,
                    'password' => Hash::make($tahun_lahir), // default password = tahun_lahir (hashed)
                    'kelas_id' => $k->id,
                    'is_alumni' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}
