<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('beritas', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('image')->nullable(); // store under storage/app/public/beritas/
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete(); // user (admin/guru)
            $table->boolean('is_published')->default(true);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['created_by', 'is_published']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('beritas');
    }
};
