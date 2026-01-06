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
        // Authors table indexes
        if (Schema::hasTable('authors')) {
            Schema::table('authors', function (Blueprint $table) {
                $table->index('name', 'idx_authors_name');
                $table->index('created_at', 'idx_authors_created');
            });
        }

        // Series table indexes  
        if (Schema::hasTable('series')) {
            Schema::table('series', function (Blueprint $table) {
                $table->index('name', 'idx_series_name');
                $table->index('created_at', 'idx_series_created');
            });
        }

        // Genres table indexes
        if (Schema::hasTable('genres')) {
            Schema::table('genres', function (Blueprint $table) {
                $table->index('name', 'idx_genres_name');
                $table->index('created_at', 'idx_genres_created');
            });
        }

        // Users table indexes
        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                $table->index('email', 'idx_users_email');
                $table->index(['role', 'created_at'], 'idx_users_role_created');
                $table->index('last_login_at', 'idx_users_last_login');
            });
        }

        // Pivot table indexes for faster joins
        if (Schema::hasTable('author_book')) {
            Schema::table('author_book', function (Blueprint $table) {
                $table->index(['author_id', 'book_id'], 'idx_author_book_composite');
                $table->index('book_id', 'idx_author_book_book_id');
            });
        }

        if (Schema::hasTable('book_series')) {
            Schema::table('book_series', function (Blueprint $table) {
                $table->index(['book_id', 'series_id'], 'idx_book_series_composite');
                $table->index('series_id', 'idx_book_series_series_id');
                $table->index(['series_id', 'series_number'], 'idx_book_series_number');
            });
        }

        if (Schema::hasTable('book_genre')) {
            Schema::table('book_genre', function (Blueprint $table) {
                $table->index(['book_id', 'genre_id'], 'idx_book_genre_composite');
                $table->index('genre_id', 'idx_book_genre_genre_id');
            });
        }

        if (Schema::hasTable('book_narrator')) {
            Schema::table('book_narrator', function (Blueprint $table) {
                $table->index(['book_id', 'narrator_id'], 'idx_book_narrator_composite');
                $table->index('narrator_id', 'idx_book_narrator_narrator_id');
            });
        }

        // Reading progress indexes
        if (Schema::hasTable('reading_progress')) {
            Schema::table('reading_progress', function (Blueprint $table) {
                $table->index(['user_id', 'book_id'], 'idx_reading_progress_user_book');
                $table->index('updated_at', 'idx_reading_progress_updated');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('authors')) {
            Schema::table('authors', function (Blueprint $table) {
                $table->dropIndex('idx_authors_name');
                $table->dropIndex('idx_authors_created');
            });
        }

        if (Schema::hasTable('series')) {
            Schema::table('series', function (Blueprint $table) {
                $table->dropIndex('idx_series_name');
                $table->dropIndex('idx_series_created');
            });
        }

        if (Schema::hasTable('genres')) {
            Schema::table('genres', function (Blueprint $table) {
                $table->dropIndex('idx_genres_name');
                $table->dropIndex('idx_genres_created');
            });
        }

        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropIndex('idx_users_email');
                $table->dropIndex('idx_users_role_created');
                $table->dropIndex('idx_users_last_login');
            });
        }

        if (Schema::hasTable('author_book')) {
            Schema::table('author_book', function (Blueprint $table) {
                $table->dropIndex('idx_author_book_composite');
                $table->dropIndex('idx_author_book_book_id');
            });
        }

        if (Schema::hasTable('book_series')) {
            Schema::table('book_series', function (Blueprint $table) {
                $table->dropIndex('idx_book_series_composite');
                $table->dropIndex('idx_book_series_series_id');
                $table->dropIndex('idx_book_series_number');
            });
        }

        if (Schema::hasTable('book_genre')) {
            Schema::table('book_genre', function (Blueprint $table) {
                $table->dropIndex('idx_book_genre_composite');
                $table->dropIndex('idx_book_genre_genre_id');
            });
        }

        if (Schema::hasTable('book_narrator')) {
            Schema::table('book_narrator', function (Blueprint $table) {
                $table->dropIndex('idx_book_narrator_composite');
                $table->dropIndex('idx_book_narrator_narrator_id');
            });
        }

        if (Schema::hasTable('reading_progress')) {
            Schema::table('reading_progress', function (Blueprint $table) {
                $table->dropIndex('idx_reading_progress_user_book');
                $table->dropIndex('idx_reading_progress_updated');
            });
        }
    }
};
