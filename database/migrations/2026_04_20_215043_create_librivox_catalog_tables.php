<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('librivox_books', function (Blueprint $table): void {
            $table->id();
            $table->string('librivox_id')->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('language')->nullable();
            $table->string('cover_image')->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->unsignedInteger('duration')->nullable();
            $table->unsignedSmallInteger('audio_file_count')->nullable();
            $table->json('librivox_info')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['language', 'created_at']);
            $table->index('title');
        });

        Schema::create('librivox_authors', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('librivox_book_author', function (Blueprint $table): void {
            $table->unsignedBigInteger('book_id');
            $table->unsignedBigInteger('author_id');
            $table->primary(['book_id', 'author_id']);
            $table->foreign('book_id')->references('id')->on('librivox_books')->cascadeOnDelete();
            $table->foreign('author_id')->references('id')->on('librivox_authors')->cascadeOnDelete();
        });

        Schema::create('librivox_genres', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('librivox_book_genre', function (Blueprint $table): void {
            $table->unsignedBigInteger('book_id');
            $table->unsignedBigInteger('genre_id');
            $table->primary(['book_id', 'genre_id']);
            $table->foreign('book_id')->references('id')->on('librivox_books')->cascadeOnDelete();
            $table->foreign('genre_id')->references('id')->on('librivox_genres')->cascadeOnDelete();
        });

        Schema::create('librivox_chapters', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('book_id');
            $table->unsignedSmallInteger('chapter_number')->default(0);
            $table->string('title')->nullable();
            $table->string('reader')->nullable();
            $table->string('file_name');
            $table->string('format')->nullable();
            $table->unsignedInteger('duration')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('listen_url')->nullable();
            $table->timestamps();

            $table->unique(['book_id', 'chapter_number']);
            $table->foreign('book_id')->references('id')->on('librivox_books')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('librivox_chapters');
        Schema::dropIfExists('librivox_book_genre');
        Schema::dropIfExists('librivox_genres');
        Schema::dropIfExists('librivox_book_author');
        Schema::dropIfExists('librivox_authors');
        Schema::dropIfExists('librivox_books');
    }
};
