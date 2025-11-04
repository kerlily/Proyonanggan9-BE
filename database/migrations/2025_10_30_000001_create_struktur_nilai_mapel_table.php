// database/migrations/2025_10_30_000001_create_struktur_nilai_mapel_table.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('struktur_nilai_mapel', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mapel_id')->constrained('mapel')->cascadeOnDelete();
            $table->foreignId('kelas_id')->constrained('kelas')->cascadeOnDelete();
            $table->foreignId('semester_id')->constrained('semester')->cascadeOnDelete();
            $table->foreignId('tahun_ajaran_id')->constrained('tahun_ajaran')->cascadeOnDelete();
            $table->foreignId('created_by_guru_id')->nullable()->constrained('guru')->nullOnDelete();
            $table->json('struktur');
            $table->timestamps();

            $table->unique(['mapel_id', 'kelas_id', 'semester_id', 'tahun_ajaran_id'], 'unique_struktur_mapel');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('struktur_nilai_mapel');
    }
};
