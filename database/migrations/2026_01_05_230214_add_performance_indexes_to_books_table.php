<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            // Index for book searches (only if not exists)
            if (!$this->indexExists('idx_books_title')) {
                $table->index('title', 'idx_books_title');
            }
            
            // Index for directory path lookups
            if (!$this->indexExists('idx_books_directory_path')) {
                $table->index('directory_path', 'idx_books_directory_path');
            }
            
            // Composite index for recent books listings
            if (!$this->indexExists('idx_books_created_title')) {
                $table->index(['created_at', 'title'], 'idx_books_created_title');
            }
            
            // Index for release date queries
            if (!$this->indexExists('idx_books_release_date')) {
                $table->index('release_date', 'idx_books_release_date');
            }
            
            // Index for cover image lookups
            if (!$this->indexExists('idx_books_cover_image')) {
                $table->index('cover_image', 'idx_books_cover_image');
            }
            
            // Index for ISBN lookups
            if (!$this->indexExists('idx_books_isbn')) {
                $table->index('isbn', 'idx_books_isbn');
            }
            
            // Index for needs review filtering
            if (!$this->indexExists('idx_books_needs_review')) {
                $table->index(['needs_review', 'created_at'], 'idx_books_needs_review');
            }
            
            // Index for language filtering
            if (!$this->indexExists('idx_books_language')) {
                $table->index('language', 'idx_books_language');
            }
            
            // Index for source filtering
            if (!$this->indexExists('idx_books_source')) {
                $table->index('source', 'idx_books_source');
            }
        });
    }

    /**
     * Check if index exists
     */
    private function indexExists(string $indexName): bool
    {
        $indexes = collect(DB::select("SHOW INDEX FROM books WHERE Key_name = ?", [$indexName]));
        return $indexes->isNotEmpty();
    }
            
            // Index for directory path lookups
            $table->index('directory_path', 'idx_books_directory_path');
            
            // Composite index for recent books listings
            $table->index(['created_at', 'title'], 'idx_books_created_title');
            
            // Index for release date queries
            $table->index('release_date', 'idx_books_release_date');
            
            // Index for cover image lookups
            $table->index('cover_image', 'idx_books_cover_image');
            
            // Index for ISBN lookups
            $table->index('isbn', 'idx_books_isbn');
            
            // Index for needs review filtering
            $table->index(['needs_review', 'created_at'], 'idx_books_needs_review');
            
            // Index for language filtering
            $table->index('language', 'idx_books_language');
            
            // Index for source filtering
            $table->index('source', 'idx_books_source');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->dropIndex('idx_books_title');
            $table->dropIndex('idx_books_directory_path');
            $table->dropIndex('idx_books_created_title');
            $table->dropIndex('idx_books_release_date');
            $table->dropIndex('idx_books_cover_image');
            $table->dropIndex('idx_books_isbn');
            $table->dropIndex('idx_books_needs_review');
            $table->dropIndex('idx_books_language');
            $table->dropIndex('idx_books_source');
        });
    }
};
