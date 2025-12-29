<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('jadwal_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kelas_id')->constrained('kelas')->cascadeOnDelete();
            $table->foreignId('semester_id')->constrained('semester')->cascadeOnDelete();
            $table->foreignId('tahun_ajaran_id')->constrained('tahun_ajaran')->cascadeOnDelete();
            $table->string('nama')->nullable(); // optional: "Jadwal Semester Ganjil 2024/2025"
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Satu kelas hanya punya 1 jadwal aktif per semester
            $table->unique(['kelas_id', 'semester_id', 'tahun_ajaran_id'], 'unique_kelas_semester_tahun');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jadwal_templates');
    }
};
