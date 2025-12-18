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
        Schema::table('wali_kelas', function (Blueprint $table) {
            Schema::table('wali_kelas', function (Blueprint $table) {
    $table->boolean('is_primary')->default(false)->after('tahun_ajaran_id');
        });
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wali_kelas', function (Blueprint $table) {
            //
        });
    }
};
