<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            KelasSeeder::class,
            TahunAjaranSeeder::class,
            UserSeeder::class,
            GuruSeeder::class,
            MapelSeeder::class,
            SiswaSeeder::class,
            JadwalSeeder::class,   // <-- baru
    BeritaSeeder::class,   // <-- baru
        ]);
    }
}
