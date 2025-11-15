<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Migration untuk table nilai_sikap
     * Menyimpan nilai sikap (A-E) dan deskripsi per siswa per semester
     */
    public function up(): void
    {
        Schema::create('nilai_sikap', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id')->constrained('siswa')->cascadeOnDelete();
            $table->foreignId('semester_id')->constrained('semester')->cascadeOnDelete();
            $table->foreignId('tahun_ajaran_id')->constrained('tahun_ajaran')->cascadeOnDelete();

            // Nilai sikap: A (Sangat Baik), B (Baik), C (Cukup), D (Kurang), E (Sangat Kurang)
            $table->enum('nilai', ['A', 'B', 'C', 'D', 'E'])->nullable();

            // Deskripsi sikap siswa
            $table->text('deskripsi')->nullable();

            // Siapa yang input (wali kelas biasanya)
            $table->foreignId('input_by_guru_id')->nullable()->constrained('guru')->nullOnDelete();

            $table->timestamps();

            // Constraint: satu siswa hanya punya 1 nilai sikap per semester per tahun ajaran
            $table->unique(['siswa_id', 'semester_id', 'tahun_ajaran_id'], 'unique_nilai_sikap');

            // Index untuk performa query
            $table->index(['semester_id', 'tahun_ajaran_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nilai_sikap');
    }
};
