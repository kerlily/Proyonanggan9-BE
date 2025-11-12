<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_status', function (Blueprint $table) {
            $table->id();
            $table->string('filename');
            $table->string('disk');
            $table->bigInteger('size')->unsigned();
            $table->timestamp('backup_date');
            $table->enum('status', ['success', 'failed'])->default('success');
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index('backup_date');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_status');
    }
};
