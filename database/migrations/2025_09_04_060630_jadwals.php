<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('jadwals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kelas_id')->constrained('kelas')->cascadeOnDelete();
            $table->foreignId('guru_id')->nullable()->constrained('guru')->nullOnDelete(); // pembuat (wali kelas)
            $table->foreignId('semester_id')->nullable()->constrained('semester')->nullOnDelete();
            $table->string('image'); // path/filename, store under storage/app/public/jadwals/
            $table->string('title')->nullable(); // optional judul/label jadwal
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true); // apakah jadwal aktif (opsional)
            $table->timestamps();

            $table->index(['kelas_id', 'semester_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jadwals');
    }
};
