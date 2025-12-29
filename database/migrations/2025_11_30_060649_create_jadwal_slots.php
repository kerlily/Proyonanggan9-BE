<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('jadwal_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('jadwal_template_id')->constrained('jadwal_templates')->cascadeOnDelete();

            // Hari & Waktu
            $table->enum('hari', ['senin', 'selasa', 'rabu', 'kamis', 'jumat', 'sabtu']);
            $table->time('jam_mulai'); // 07:00:00
            $table->time('jam_selesai'); // 07:45:00

            // Tipe slot: pelajaran atau istirahat
            $table->enum('tipe_slot', ['pelajaran', 'istirahat'])->default('pelajaran');

            // Untuk tipe pelajaran
            $table->foreignId('mapel_id')->nullable()->constrained('mapel')->nullOnDelete();

            // Untuk tipe istirahat
            $table->string('keterangan')->nullable(); // "Istirahat", "Sholat Dzuhur", "Upacara"

            // Sorting
            $table->integer('urutan')->default(0);

            $table->timestamps();

            // Index untuk query
            $table->index(['jadwal_template_id', 'hari', 'urutan']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jadwal_slots');
    }
};
