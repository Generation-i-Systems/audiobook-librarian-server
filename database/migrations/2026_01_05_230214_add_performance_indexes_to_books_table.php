<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('books')) {
            return;
        }

        Schema::table('books', function (Blueprint $table) {
            // Individual indexes requested by user
            if (!$this->indexExists('idx_books_created_at')) {
                $table->index('created_at', 'idx_books_created_at');
            }

            if (!$this->indexExists('idx_books_needs_review')) {
                $table->index('needs_review', 'idx_books_needs_review');
            }

            if (!$this->indexExists('idx_books_cover_image')) {
                $table->index('cover_image', 'idx_books_cover_image');
            }

            if (!$this->indexExists('idx_books_directory_path')) {
                $table->index('directory_path', 'idx_books_directory_path');
            }

            // Other performance indexes
            if (!$this->indexExists('idx_books_title')) {
                $table->index('title', 'idx_books_title');
            }

            if (!$this->indexExists('idx_books_release_date')) {
                $table->index('release_date', 'idx_books_release_date');
            }

            if (!$this->indexExists('idx_books_isbn')) {
                $table->index('isbn', 'idx_books_isbn');
            }

            if (!$this->indexExists('idx_books_language')) {
                $table->index('language', 'idx_books_language');
            }

            if (!$this->indexExists('idx_books_source')) {
                $table->index('source', 'idx_books_source');
            }

            // Composite indexes for specific query patterns
            if (!$this->indexExists('idx_books_created_title')) {
                $table->index(['created_at', 'title'], 'idx_books_created_title');
            }

            if (!$this->indexExists('idx_books_needs_review_composite')) {
                $table->index(['needs_review', 'created_at'], 'idx_books_needs_review_composite');
            }
        });
    }

    /**
     * Check if index exists
     */
    private function indexExists(string $indexName): bool
    {
        try {
            // This works for MySQL. For SQLite (tests), we'd need a different approach
            // but Schema::table handles things gracefully or we can just catch exceptions.
            $indexes = collect(DB::select("SHOW INDEX FROM books WHERE Key_name = ?", [$indexName]));
            return $indexes->isNotEmpty();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('books')) {
            return;
        }

        Schema::table('books', function (Blueprint $table) {
            $table->dropIndex('idx_books_created_at');
            $table->dropIndex('idx_books_needs_review');
            $table->dropIndex('idx_books_cover_image');
            $table->dropIndex('idx_books_directory_path');
            $table->dropIndex('idx_books_title');
            $table->dropIndex('idx_books_release_date');
            $table->dropIndex('idx_books_isbn');
            $table->dropIndex('idx_books_language');
            $table->dropIndex('idx_books_source');
            $table->dropIndex('idx_books_created_title');
            $table->dropIndex('idx_books_needs_review_composite');
        });
    }
};
