<?php
// app/Console/Commands/SyncRiwayatKelas.php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Siswa;
use App\Models\TahunAjaran;
use App\Models\RiwayatKelas;

class SyncRiwayatKelas extends Command
{
    protected $signature = 'siswa:sync-riwayat';
    protected $description = 'Sync riwayat kelas untuk semua siswa';

    public function handle()
    {
        $this->info('🔄 Mulai sync riwayat kelas...');

        $activeTahunAjaran = TahunAjaran::where('is_active', true)->first();

        if (!$activeTahunAjaran) {
            $this->error('❌ Tidak ada tahun ajaran aktif!');
            return 1;
        }

        $this->info("📅 Tahun Ajaran: {$activeTahunAjaran->nama}");

        $siswaList = Siswa::whereNotNull('kelas_id')
            ->whereNull('deleted_at')
            ->get();

        $this->info("👥 Total siswa: {$siswaList->count()}");

        $created = 0;
        $updated = 0;

        foreach ($siswaList as $siswa) {
            $riwayat = RiwayatKelas::updateOrCreate(
                [
                    'siswa_id' => $siswa->id,
                    'tahun_ajaran_id' => $activeTahunAjaran->id,
                ],
                [
                    'kelas_id' => $siswa->kelas_id,
                ]
            );

            if ($riwayat->wasRecentlyCreated) {
                $created++;
            } else {
                $updated++;
            }
        }

        $this->info("✅ Selesai!");
        $this->info("   • Dibuat: {$created}");
        $this->info("   • Diupdate: {$updated}");

        return 0;
    }
}
