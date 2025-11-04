// database/migrations/2025_10_30_000003_add_is_generated_to_nilai_table.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nilai', function (Blueprint $table) {
            $table->boolean('is_generated')->default(false)->after('nilai');
            $table->text('sumber_perhitungan')->nullable()->after('is_generated');
        });
    }

    public function down(): void
    {
        Schema::table('nilai', function (Blueprint $table) {
            $table->dropColumn(['is_generated', 'sumber_perhitungan']);
        });
    }
};
