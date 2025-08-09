<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('books', function (Blueprint $table) {
            $table->id();
            $table->string('mongo_id')->nullable()->index();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('directory_path')->nullable();
            $table->date('release_date')->nullable();
            $table->string('cover_image')->nullable();
            $table->string('isbn')->nullable(); // Added ISBN column
            $table->string('language')->nullable();
            $table->string('source')->nullable();
            $table->foreignId('series_id')->nullable()->constrained()->onDelete('set null');
            $table->integer('duration')->nullable();
            $table->string('publisher')->nullable();
            $table->boolean('needs_review')->default(false);
            $table->text('needs_review_reasons')->nullable();
            $table->integer('audio_file_count')->nullable();
            $table->json('file_tags')->nullable();
            $table->json('audible_info')->nullable();
            $table->json('google_books_info')->nullable();
            $table->json('audiobook_bay_info')->nullable();
            $table->json('hardcover_info')->nullable();
            $table->json('mongo_record')->nullable();
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
