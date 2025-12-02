<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * PERHATIAN: Migration ini akan menghapus tabel jadwals lama beserta semua datanya.
     * Pastikan sudah backup data jika diperlukan!
     */
    public function up(): void
    {
        Schema::dropIfExists('jadwals');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Recreate old table structure (untuk rollback)
        Schema::create('jadwals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kelas_id')->constrained('kelas')->cascadeOnDelete();
            $table->foreignId('guru_id')->nullable()->constrained('guru')->nullOnDelete();
            $table->foreignId('semester_id')->nullable()->constrained('semester')->nullOnDelete();
            $table->string('image');
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['kelas_id', 'semester_id']);
        });
    }
};
