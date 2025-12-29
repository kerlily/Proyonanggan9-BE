<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * ✅ SUPER CRITICAL untuk Shared Hosting!
     * Index database = 10x lebih cepat dari Redis!
     */
    public function up(): void
    {
        // ✅ Index #1: Login siswa (nama + kelas_id)
        // Ini PALING PENTING karena query login: WHERE nama=? AND kelas_id=?
        if (!$this->indexExists('siswa', 'idx_siswa_login')) {
            Schema::table('siswa', function (Blueprint $table) {
                $table->index(['nama', 'kelas_id'], 'idx_siswa_login');
            });
        }

        // ✅ Index #2: Query per kelas
        if (!$this->indexExists('siswa', 'idx_siswa_kelas')) {
            Schema::table('siswa', function (Blueprint $table) {
                $table->index('kelas_id', 'idx_siswa_kelas');
            });
        }

        // ✅ Index #3: Filter alumni
        if (!$this->indexExists('siswa', 'idx_siswa_alumni')) {
            Schema::table('siswa', function (Blueprint $table) {
                $table->index('is_alumni', 'idx_siswa_alumni');
            });
        }

        // ✅ Optimize table (optional, tapi bagus untuk performa)
        DB::statement('OPTIMIZE TABLE siswa');
    }

    /**
     * Helper: Check if index exists
     */
    private function indexExists(string $table, string $index): bool
    {
        $indexes = DB::select("SHOW INDEX FROM {$table}");
        foreach ($indexes as $idx) {
            if ($idx->Key_name === $index) {
                return true;
            }
        }
        return false;
    }

    public function down(): void
    {
        Schema::table('siswa', function (Blueprint $table) {
            $table->dropIndex('idx_siswa_login');
            $table->dropIndex('idx_siswa_kelas');
            $table->dropIndex('idx_siswa_alumni');
        });
    }
};
