// database/migrations/2025_10_30_000002_create_nilai_detail_table.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nilai_detail', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id')->constrained('siswa')->cascadeOnDelete();
            $table->foreignId('mapel_id')->constrained('mapel')->cascadeOnDelete();
            $table->foreignId('semester_id')->constrained('semester')->cascadeOnDelete();
            $table->foreignId('tahun_ajaran_id')->constrained('tahun_ajaran')->cascadeOnDelete();
            $table->foreignId('struktur_nilai_mapel_id')->constrained('struktur_nilai_mapel')->cascadeOnDelete();
            $table->string('lm_key');
            $table->string('kolom_key');
            $table->decimal('nilai', 5, 2)->nullable();
            $table->foreignId('input_by_guru_id')->nullable()->constrained('guru')->nullOnDelete();
            $table->timestamps();

            $table->unique(['siswa_id', 'struktur_nilai_mapel_id', 'lm_key', 'kolom_key'], 'unique_nilai_detail');
            $table->index(['mapel_id', 'semester_id', 'tahun_ajaran_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nilai_detail');
    }
};
