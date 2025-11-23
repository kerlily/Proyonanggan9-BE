<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('nilai_detail', function (Blueprint $table) {
            // Ubah lm_key jadi nullable
            $table->string('lm_key')->nullable()->change();
        });
    }

    public function down()
    {
        Schema::table('nilai_detail', function (Blueprint $table) {
            $table->string('lm_key')->nullable(false)->change();
        });
    }
};
