<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class KelasSeeder extends Seeder
{
    public function run(): void
    {
        $sections = ['A','B'];
        for ($grade = 1; $grade <= 6; $grade++) {
            foreach ($sections as $sec) {
                DB::table('kelas')->insert([
                    'nama' => $grade . $sec,
                    'tingkat' => $grade,
                    'section' => $sec,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}
