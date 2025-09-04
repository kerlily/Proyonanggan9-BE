<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MapelSeeder extends Seeder
{
    public function run(): void
    {
        $mapels = ['Matematika','Bahasa Indonesia','IPA','IPS','PJOK','Seni Budaya'];
        foreach ($mapels as $m) {
            DB::table('mapel')->insert([
                'nama' => $m,
                'kode' => strtoupper(substr($m,0,3)),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
