<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Tambah kolom catatan di tabel nilai_detail tapi akan digunakan
     * untuk menyimpan catatan per siswa per struktur (per mapel)
     * Catatan ini hanya 1 untuk semua nilai detail siswa di mapel tersebut
     */
    public function up(): void
    {
        // Opsi 1: Gunakan kolom catatan di nilai_detail (tapi hanya isi di 1 row)
        // Opsi 2: Buat tabel baru catatan_mapel_siswa

        // Kita pilih Opsi 2 yang lebih clean
        Schema::create('catatan_mapel_siswa', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id')->constrained('siswa')->cascadeOnDelete();
            $table->foreignId('struktur_nilai_mapel_id')->constrained('struktur_nilai_mapel')->cascadeOnDelete();
            $table->text('catatan')->nullable();
            $table->foreignId('input_by_guru_id')->nullable()->constrained('guru')->nullOnDelete();
            $table->timestamps();

            // Constraint: 1 catatan per siswa per struktur (mapel-semester-tahun)
            $table->unique(['siswa_id', 'struktur_nilai_mapel_id'], 'unique_catatan_mapel_siswa');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('catatan_mapel_siswa');
    }
};
