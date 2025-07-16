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
        Schema::create('books', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedInteger('publication_year')->nullable();
            $table->string('cover_image')->nullable();
            $table->string('language')->nullable();
            $table->string('book_number')->nullable();
            $table->string('path')->nullable();
            $table->string('source')->nullable();
            $table->string('audio_sample_path')->nullable();
            $table->foreignId('series_id')->nullable()->constrained()->onDelete('set null');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('books');
    }
};
