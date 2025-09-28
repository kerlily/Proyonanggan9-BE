<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Alternative approach: Recreate table if constraints are too complex
     */
    public function up(): void
    {
        // Step 1: Backup current data
        DB::statement('CREATE TEMPORARY TABLE nilai_backup SELECT * FROM nilai');

        // Step 2: Drop current nilai table
        Schema::dropIfExists('nilai');

        // Step 3: Recreate nilai table with proper structure
        Schema::create('nilai', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id')->constrained('siswa')->cascadeOnDelete();
            $table->foreignId('mapel_id')->constrained('mapel')->cascadeOnDelete();
            $table->foreignId('semester_id')->constrained('semester')->cascadeOnDelete();
            $table->foreignId('tahun_ajaran_id')->constrained('tahun_ajaran')->cascadeOnDelete();
            $table->integer('nilai')->nullable();
            $table->text('catatan')->nullable();
            $table->foreignId('input_by_guru_id')->nullable()->constrained('guru')->nullOnDelete();
            $table->timestamps();

            // New unique constraint with tahun_ajaran_id
            $table->unique(['siswa_id','mapel_id','semester_id','tahun_ajaran_id'], 'unique_nilai_per_tahun');
        });

        // Step 4: Restore data with tahun_ajaran_id populated from semester
        DB::statement('
            INSERT INTO nilai (siswa_id, mapel_id, semester_id, tahun_ajaran_id, nilai, catatan, input_by_guru_id, created_at, updated_at)
            SELECT
                nb.siswa_id,
                nb.mapel_id,
                nb.semester_id,
                s.tahun_ajaran_id,
                nb.nilai,
                nb.catatan,
                nb.input_by_guru_id,
                nb.created_at,
                nb.updated_at
            FROM nilai_backup nb
            JOIN semester s ON s.id = nb.semester_id
        ');

        // Step 5: Drop temporary table
        DB::statement('DROP TEMPORARY TABLE nilai_backup');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Backup data without tahun_ajaran_id
        DB::statement('CREATE TEMPORARY TABLE nilai_backup SELECT siswa_id, mapel_id, semester_id, nilai, catatan, input_by_guru_id, created_at, updated_at FROM nilai');

        // Drop current table
        Schema::dropIfExists('nilai');

        // Recreate original table structure
        Schema::create('nilai', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id')->constrained('siswa')->cascadeOnDelete();
            $table->foreignId('mapel_id')->constrained('mapel')->cascadeOnDelete();
            $table->foreignId('semester_id')->constrained('semester')->cascadeOnDelete();
            $table->integer('nilai')->nullable();
            $table->text('catatan')->nullable();
            $table->foreignId('input_by_guru_id')->nullable()->constrained('guru')->nullOnDelete();
            $table->timestamps();

            // Original unique constraint
            $table->unique(['siswa_id','mapel_id','semester_id']);
        });

        // Restore data
        DB::statement('
            INSERT INTO nilai (siswa_id, mapel_id, semester_id, nilai, catatan, input_by_guru_id, created_at, updated_at)
            SELECT siswa_id, mapel_id, semester_id, nilai, catatan, input_by_guru_id, created_at, updated_at
            FROM nilai_backup
        ');

        // Drop temporary table
        DB::statement('DROP TEMPORARY TABLE nilai_backup');
    }
};
