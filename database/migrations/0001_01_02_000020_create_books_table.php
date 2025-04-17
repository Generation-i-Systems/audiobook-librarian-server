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
            $table->foreignId('author_id')->constrained()->onDelete('cascade');
            $table->string('series')->nullable();
            $table->foreignId('genre_id')->constrained()->onDelete('cascade');
            $table->string('cover_image')->nullable(); // Path to cover image
            $table->text('description')->nullable();
            $table->string('directory_path')->nullable();  // Store path to the book's directory
            $table->string('type'); // 'ebook' or 'audiobook'
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
