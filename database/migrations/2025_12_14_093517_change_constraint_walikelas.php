<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Cek foreign key yang ada
        $foreignKeys = DB::select("
            SELECT CONSTRAINT_NAME
            FROM information_schema.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'wali_kelas'
            AND CONSTRAINT_TYPE = 'FOREIGN KEY'
        ");

        Schema::table('wali_kelas', function (Blueprint $table) use ($foreignKeys) {
            // Drop semua foreign key yang ada
            foreach ($foreignKeys as $fk) {
                $table->dropForeign($fk->CONSTRAINT_NAME);
            }
        });

        Schema::table('wali_kelas', function (Blueprint $table) {
            // Hapus unique constraint lama
            $table->dropUnique('wali_kelas_kelas_id_tahun_ajaran_id_unique');

            // Tambah kolom is_primary jika belum ada
            if (!Schema::hasColumn('wali_kelas', 'is_primary')) {
                $table->boolean('is_primary')->default(false)->after('tahun_ajaran_id');
            }

            // Buat unique constraint baru
            $table->unique(['guru_id', 'kelas_id', 'tahun_ajaran_id'], 'wali_kelas_guru_kelas_tahun_unique');

            // Kembalikan foreign keys
            $table->foreign('guru_id')->references('id')->on('guru')->onDelete('cascade');
            $table->foreign('kelas_id')->references('id')->on('kelas')->onDelete('cascade');
            $table->foreign('tahun_ajaran_id')->references('id')->on('tahun_ajaran')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Cek foreign key yang ada
        $foreignKeys = DB::select("
            SELECT CONSTRAINT_NAME
            FROM information_schema.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'wali_kelas'
            AND CONSTRAINT_TYPE = 'FOREIGN KEY'
        ");

        Schema::table('wali_kelas', function (Blueprint $table) use ($foreignKeys) {
            // Drop semua foreign key
            foreach ($foreignKeys as $fk) {
                $table->dropForeign($fk->CONSTRAINT_NAME);
            }
        });

        Schema::table('wali_kelas', function (Blueprint $table) {
            // Hapus unique constraint baru
            $table->dropUnique('wali_kelas_guru_kelas_tahun_unique');

            // Hapus kolom is_primary
            if (Schema::hasColumn('wali_kelas', 'is_primary')) {
                $table->dropColumn('is_primary');
            }

            // Kembalikan unique constraint lama
            $table->unique(['kelas_id', 'tahun_ajaran_id'], 'wali_kelas_kelas_id_tahun_ajaran_id_unique');

            // Kembalikan foreign keys
            $table->foreign('guru_id')->references('id')->on('guru')->onDelete('cascade');
            $table->foreign('kelas_id')->references('id')->on('kelas')->onDelete('cascade');
            $table->foreign('tahun_ajaran_id')->references('id')->on('tahun_ajaran')->onDelete('cascade');
        });
    }
};
