<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
public function up(): void
{
    Schema::table('beritas', function (Blueprint $table) {
        $table->enum('type', ['berita', 'pengumuman'])
              ->default('berita')
              ->after('title');
        $table->index(['type', 'is_published']);
    });
}

public function down(): void
{
    Schema::table('beritas', function (Blueprint $table) {
        $table->dropColumn('type');
    });
}
};
