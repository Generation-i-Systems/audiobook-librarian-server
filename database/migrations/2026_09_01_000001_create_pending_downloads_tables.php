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
        Schema::create('pending_downloads', function (Blueprint $table) {
            $table->id();
            $table->string('magnet_infohash')->nullable()->unique();
            $table->string('release_name')->nullable();
            $table->string('torrent_name_hint')->nullable();
            $table->string('abb_url');
            $table->string('abb_category')->nullable();
            $table->text('magnet_uri');
            $table->unsignedSmallInteger('book_count')->default(1);
            $table->string('status')->default('pending');
            $table->string('matched_book_directory')->nullable();
            $table->timestamp('matched_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('release_name');
            $table->index('status');
            $table->index('expires_at');
        });

        Schema::create('pending_download_books', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pending_download_id')->constrained('pending_downloads')->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('title');
            $table->json('authors')->nullable();
            $table->string('genre')->nullable();
            $table->json('tags')->nullable();
            $table->text('description')->nullable();
            $table->string('cover_url')->nullable();
            $table->string('series_name')->nullable();
            $table->string('series_number')->nullable();
            $table->string('abb_url')->nullable();
            $table->string('narrator')->nullable();
            $table->foreignId('matched_book_id')->nullable()->constrained('books')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pending_download_books');
        Schema::dropIfExists('pending_downloads');
    }
};
