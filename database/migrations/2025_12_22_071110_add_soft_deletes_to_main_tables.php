<?php
// database/migrations/2025_12_22_000001_add_soft_deletes_to_main_tables.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Users (Guru/Admin)
        Schema::table('users', function (Blueprint $table) {
            $table->softDeletes()->after('updated_at');
        });

        // 2. Siswa
        Schema::table('siswa', function (Blueprint $table) {
            $table->softDeletes()->after('updated_at');
        });

        // 3. Guru
        Schema::table('guru', function (Blueprint $table) {
            $table->softDeletes()->after('updated_at');
        });

        // 4. Kelas
        Schema::table('kelas', function (Blueprint $table) {
            $table->softDeletes()->after('updated_at');
        });

        // 5. Nilai (optional but recommended)
        Schema::table('nilai', function (Blueprint $table) {
            $table->softDeletes()->after('updated_at');
        });

        // 6. Index untuk performa query dengan soft delete
        Schema::table('users', function (Blueprint $table) {
            $table->index('deleted_at');
        });

        Schema::table('siswa', function (Blueprint $table) {
            $table->index('deleted_at');
        });

        Schema::table('guru', function (Blueprint $table) {
            $table->index('deleted_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['deleted_at']);
            $table->dropSoftDeletes();
        });

        Schema::table('siswa', function (Blueprint $table) {
            $table->dropIndex(['deleted_at']);
            $table->dropSoftDeletes();
        });

        Schema::table('guru', function (Blueprint $table) {
            $table->dropIndex(['deleted_at']);
            $table->dropSoftDeletes();
        });

        Schema::table('kelas', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('nilai', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
