<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('riwayat_kelas', function (Blueprint $table) {
            // Drop foreign key first
            $table->dropForeign(['kelas_id']);

            // Make kelas_id nullable
            $table->foreignId('kelas_id')->nullable()->change();

            // Re-add foreign key with nullOnDelete
            $table->foreign('kelas_id')->references('id')->on('kelas')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('riwayat_kelas', function (Blueprint $table) {
            // Drop foreign key
            $table->dropForeign(['kelas_id']);

            // Make kelas_id NOT NULL (but this might fail if there's null data)
            $table->foreignId('kelas_id')->nullable(false)->change();

            // Re-add foreign key
            $table->foreign('kelas_id')->references('id')->on('kelas')->cascadeOnDelete();
        });
    }
};
