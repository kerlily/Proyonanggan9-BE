<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BeritaSeeder extends Seeder
{
    public function run(): void
    {
        // ambil satu admin dan satu guru sebagai penulis contoh
        $adminUser = DB::table('users')->where('role', 'admin')->first();
        $guruUser = DB::table('users')->where('role', 'guru')->first();

        if (! $adminUser && ! $guruUser) {
            return;
        }

        // contoh berita dari admin
        if ($adminUser) {
            DB::table('beritas')->insert([
                'title' => 'Pengumuman Libur Sekolah',
                'description' => 'Libur sekolah pada tanggal 1 Januari karena ...',
                'image' => 'beritas/announcement_' . now()->format('Ymd') . '.jpg',
                'created_by' => $adminUser->id,
                'is_published' => true,
                'published_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // contoh berita dari guru
        if ($guruUser) {
            DB::table('beritas')->insert([
                'title' => 'Kegiatan Prakarya Kelas',
                'description' => 'Kegiatan prakarya akan dilaksanakan pada hari Jumat...',
                'image' => 'beritas/prakarya_' . now()->format('Ymd') . '.jpg',
                'created_by' => $guruUser->id,
                'is_published' => true,
                'published_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
