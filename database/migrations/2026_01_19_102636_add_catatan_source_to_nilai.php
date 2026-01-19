<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
{
    // 1. Tambah kolom catatan_source DULU
    Schema::table('nilai', function (Blueprint $table) {
        if (!Schema::hasColumn('nilai', 'catatan_source')) {
            $table->enum('catatan_source', ['manual', 'generated', 'excel'])
                ->default('manual')
                ->after('catatan');
        }
    });

    // 2. Set semua existing catatan sebagai 'manual' (OPSI C)
    DB::statement("
        UPDATE nilai
        SET catatan_source = 'manual'
        WHERE catatan IS NOT NULL AND catatan != '' AND catatan != '-'
    ");

    // 3. Backup catatan ke catatan_mapel_siswa (hanya yang punya struktur)
    DB::statement("
        INSERT IGNORE INTO catatan_mapel_siswa (siswa_id, struktur_nilai_mapel_id, catatan, input_by_guru_id, created_at, updated_at)
        SELECT
            n.siswa_id,
            snm.id,
            n.catatan,
            n.input_by_guru_id,
            NOW(),
            NOW()
        FROM nilai n
        INNER JOIN struktur_nilai_mapel snm
            ON snm.mapel_id = n.mapel_id
            AND snm.semester_id = n.semester_id
            AND snm.tahun_ajaran_id = n.tahun_ajaran_id
        WHERE n.catatan IS NOT NULL
            AND n.catatan != ''
            AND n.catatan != '-'
    ");
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nilai', function (Blueprint $table) {
            if (Schema::hasColumn('nilai', 'catatan_source')) {
                $table->dropColumn('catatan_source');
            }
        });
    }
};
