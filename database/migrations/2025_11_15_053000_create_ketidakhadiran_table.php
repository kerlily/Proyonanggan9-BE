<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Migration untuk table ketidakhadiran
     * Menyimpan total ijin, sakit, alpa per siswa per semester
     */
    public function up(): void
    {
        Schema::create('ketidakhadiran', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id')->constrained('siswa')->cascadeOnDelete();
            $table->foreignId('semester_id')->constrained('semester')->cascadeOnDelete();
            $table->foreignId('tahun_ajaran_id')->constrained('tahun_ajaran')->cascadeOnDelete();

            // Total ketidakhadiran per jenis
            $table->unsignedInteger('ijin')->default(0);
            $table->unsignedInteger('sakit')->default(0);
            $table->unsignedInteger('alpa')->default(0);

            // Catatan tambahan (opsional)
            $table->text('catatan')->nullable();

            // Siapa yang input
            $table->foreignId('input_by_guru_id')->nullable()->constrained('guru')->nullOnDelete();

            $table->timestamps();

            // Constraint: satu siswa hanya punya 1 record ketidakhadiran per semester per tahun ajaran
            $table->unique(['siswa_id', 'semester_id', 'tahun_ajaran_id'], 'unique_ketidakhadiran');

            // Index untuk performa query
            $table->index(['semester_id', 'tahun_ajaran_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ketidakhadiran');
    }
};
