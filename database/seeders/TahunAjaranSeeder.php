<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TahunAjaranSeeder extends Seeder
{
    public function run(): void
    {
        $nama = (date('Y')) . '/' . (date('Y')+1);
        $tahunId = DB::table('tahun_ajaran')->insertGetId([
            'nama' => $nama,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // create two semesters: ganjil, genap
        DB::table('semester')->insert([
            ['tahun_ajaran_id' => $tahunId, 'nama' => 'ganjil', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['tahun_ajaran_id' => $tahunId, 'nama' => 'genap', 'is_active' => false, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }
}
