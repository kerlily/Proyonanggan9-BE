<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Nilai;
use App\Models\RiwayatKelas;
use App\Models\Siswa;
use Illuminate\Support\Facades\DB;

class FixRiwayatKelasCommand extends Command
{
    protected $signature = 'fix:riwayat-kelas {--dry-run : Simulate without saving}';
    protected $description = 'Fix missing riwayat_kelas records based on existing nilai data';

    public function handle()
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('🔍 DRY RUN MODE - No data will be saved');
        } else {
            $this->info('🔧 FIXING missing riwayat_kelas records...');
        }

        // Ambil semua kombinasi unik siswa_id + tahun_ajaran_id dari tabel nilai
        // yang belum ada di riwayat_kelas
        $missingRecords = DB::table('nilai as n')
            ->select('n.siswa_id', 'n.tahun_ajaran_id')
            ->leftJoin('riwayat_kelas as rk', function($join) {
                $join->on('rk.siswa_id', '=', 'n.siswa_id')
                     ->on('rk.tahun_ajaran_id', '=', 'n.tahun_ajaran_id');
            })
            ->whereNull('rk.id') // tidak ada record di riwayat_kelas
            ->groupBy('n.siswa_id', 'n.tahun_ajaran_id')
            ->get();

        $this->info("Found {$missingRecords->count()} missing riwayat_kelas records");

        $created = 0;
        $failed = 0;

        foreach ($missingRecords as $record) {
            $siswa = Siswa::find($record->siswa_id);
            if (!$siswa) {
                $this->warn("Siswa ID {$record->siswa_id} not found, skipping...");
                $failed++;
                continue;
            }

            // Coba estimasi kelas berdasarkan kelas saat ini dan tahun ajaran
            $estimatedKelasId = $this->estimateKelasId($siswa, $record->tahun_ajaran_id);

            $data = [
                'siswa_id' => $record->siswa_id,
                'tahun_ajaran_id' => $record->tahun_ajaran_id,
                'kelas_id' => $estimatedKelasId, // bisa null jika tidak bisa diestimasi
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if ($dryRun) {
                $kelasInfo = $estimatedKelasId ?
                    DB::table('kelas')->where('id', $estimatedKelasId)->value('nama') :
                    'NULL';

                $this->line("Would create: Siswa {$siswa->nama} -> Tahun Ajaran ID {$record->tahun_ajaran_id} -> Kelas {$kelasInfo}");
                $created++;
            } else {
                try {
                    RiwayatKelas::create($data);
                    $created++;

                    $kelasInfo = $estimatedKelasId ?
                        DB::table('kelas')->where('id', $estimatedKelasId)->value('nama') :
                        'NULL';
                    $this->info("✅ Created: {$siswa->nama} -> Tahun Ajaran ID {$record->tahun_ajaran_id} -> Kelas {$kelasInfo}");
                } catch (\Exception $e) {
                    $this->error("❌ Failed to create record for siswa {$siswa->nama}: " . $e->getMessage());
                    $failed++;
                }
            }
        }

        $this->info("\n📊 Summary:");
        $this->info("✅ Created: {$created}");
        $this->info("❌ Failed: {$failed}");

        if ($dryRun) {
            $this->info("\n💡 Run without --dry-run to actually create the records");
        } else {
            $this->info("\n🎉 Done! Riwayat kelas records have been created.");
        }
    }

    /**
     * Estimasi kelas_id berdasarkan kelas saat ini siswa dan tahun ajaran target
     */
    private function estimateKelasId($siswa, $targetTahunAjaranId)
    {
        if (!$siswa->kelas_id) {
            return null; // siswa tidak punya kelas saat ini
        }

        // Ambil tahun ajaran aktif dan target
        $activeTahunAjaran = DB::table('tahun_ajaran')->where('is_active', true)->first();
        $targetTahunAjaran = DB::table('tahun_ajaran')->where('id', $targetTahunAjaranId)->first();

        if (!$activeTahunAjaran || !$targetTahunAjaran) {
            return $siswa->kelas_id; // fallback ke kelas saat ini
        }

        // Hitung selisih tahun
        $currentYear = (int) substr($activeTahunAjaran->nama, 0, 4); // "2025/2026" -> 2025
        $targetYear = (int) substr($targetTahunAjaran->nama, 0, 4);   // "2024/2025" -> 2024

        $yearDiff = $currentYear - $targetYear;

        // Ambil data kelas saat ini
        $currentKelas = DB::table('kelas')->where('id', $siswa->kelas_id)->first();
        if (!$currentKelas) {
            return null;
        }

        // Hitung tingkat estimasi
        $estimatedTingkat = $currentKelas->tingkat - $yearDiff;

        if ($estimatedTingkat < 1 || $estimatedTingkat > 6) {
            return null; // tingkat tidak valid
        }

        // Cari kelas dengan tingkat dan section yang sama
        $estimatedKelas = DB::table('kelas')
            ->where('tingkat', $estimatedTingkat)
            ->where('section', $currentKelas->section)
            ->first();

        return $estimatedKelas?->id;
    }
}
