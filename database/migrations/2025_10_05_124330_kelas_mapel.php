<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Buat pivot table untuk relasi many-to-many antara Kelas dan Mapel
     * Setiap kelas bisa punya banyak mapel, dan setiap mapel bisa ada di banyak kelas
     */
    public function up(): void
    {
        Schema::create('kelas_mapel', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kelas_id')->constrained('kelas')->cascadeOnDelete();
            $table->foreignId('mapel_id')->constrained('mapel')->cascadeOnDelete();
            $table->timestamps();

            // Constraint: satu mapel hanya bisa di-assign sekali per kelas
            $table->unique(['kelas_id', 'mapel_id']);

            // Index untuk performa query
            $table->index('kelas_id');
            $table->index('mapel_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kelas_mapel');
    }
};
