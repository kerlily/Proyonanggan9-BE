<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('nilai', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id')->constrained('siswa')->cascadeOnDelete();
            $table->foreignId('mapel_id')->constrained('mapel')->cascadeOnDelete();
            $table->foreignId('semester_id')->constrained('semester')->cascadeOnDelete();
            $table->integer('nilai')->nullable();
            $table->text('catatan')->nullable();
            $table->foreignId('input_by_guru_id')->nullable()->constrained('guru')->nullOnDelete();
            $table->timestamps();

            $table->unique(['siswa_id','mapel_id','semester_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nilai');
    }
};
