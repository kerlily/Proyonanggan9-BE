<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('guru', function (Blueprint $table) {
            // add nullable photo path
            if (! Schema::hasColumn('guru', 'photo')) {
                $table->string('photo')->nullable()->after('no_hp');
            }
        });
    }

    public function down(): void
    {
        Schema::table('guru', function (Blueprint $table) {
            if (Schema::hasColumn('guru', 'photo')) {
                $table->dropColumn('photo');
            }
        });
    }
};
