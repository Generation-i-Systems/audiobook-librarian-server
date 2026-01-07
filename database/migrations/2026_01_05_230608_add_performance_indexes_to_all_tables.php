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
        // Authors table indexes
        if (Schema::hasTable('authors')) {
            Schema::table('authors', function (Blueprint $table) {
                if (!$this->indexExists('authors', 'idx_authors_name')) {
                    $table->index('name', 'idx_authors_name');
                }
                if (!$this->indexExists('authors', 'idx_authors_created')) {
                    $table->index('created_at', 'idx_authors_created');
                }
            });
        }

        // Series table indexes
        if (Schema::hasTable('series')) {
            Schema::table('series', function (Blueprint $table) {
                if (!$this->indexExists('series', 'idx_series_name')) {
                    $table->index('name', 'idx_series_name');
                }
                if (!$this->indexExists('series', 'idx_series_created')) {
                    $table->index('created_at', 'idx_series_created');
                }
            });
        }

        // Genres table indexes
        if (Schema::hasTable('genres')) {
            Schema::table('genres', function (Blueprint $table) {
                if (!$this->indexExists('genres', 'idx_genres_name')) {
                    $table->index('name', 'idx_genres_name');
                }
                if (!$this->indexExists('genres', 'idx_genres_created')) {
                    $table->index('created_at', 'idx_genres_created');
                }
            });
        }

        // Users table indexes
        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                if (!$this->indexExists('users', 'idx_users_email')) {
                    $table->index('email', 'idx_users_email');
                }
                if (Schema::hasColumn('users', 'role') && !$this->indexExists('users', 'idx_users_role_created')) {
                    $table->index(['role', 'created_at'], 'idx_users_role_created');
                }
                if (Schema::hasColumn('users', 'last_login_at') && !$this->indexExists('users', 'idx_users_last_login')) {
                    $table->index('last_login_at', 'idx_users_last_login');
                }
            });
        }

        // Pivot table indexes for faster joins
        if (Schema::hasTable('author_book')) {
            Schema::table('author_book', function (Blueprint $table) {
                if (!$this->indexExists('author_book', 'idx_author_book_composite')) {
                    $table->index(['author_id', 'book_id'], 'idx_author_book_composite');
                }
                if (!$this->indexExists('author_book', 'idx_author_book_book_id')) {
                    $table->index('book_id', 'idx_author_book_book_id');
                }
            });
        }

        if (Schema::hasTable('book_series')) {
            Schema::table('book_series', function (Blueprint $table) {
                if (!$this->indexExists('book_series', 'idx_book_series_composite')) {
                    $table->index(['book_id', 'series_id'], 'idx_book_series_composite');
                }
                if (!$this->indexExists('book_series', 'idx_book_series_series_id')) {
                    $table->index('series_id', 'idx_book_series_series_id');
                }
                if (Schema::hasColumn('book_series', 'series_number') && !$this->indexExists('book_series', 'idx_book_series_number')) {
                    $table->index(['series_id', 'series_number'], 'idx_book_series_number');
                }
            });
        }

        if (Schema::hasTable('book_genre')) {
            Schema::table('book_genre', function (Blueprint $table) {
                if (!$this->indexExists('book_genre', 'idx_book_genre_composite')) {
                    $table->index(['book_id', 'genre_id'], 'idx_book_genre_composite');
                }
                if (!$this->indexExists('book_genre', 'idx_book_genre_genre_id')) {
                    $table->index('genre_id', 'idx_book_genre_genre_id');
                }
            });
        }

        if (Schema::hasTable('book_narrator')) {
            Schema::table('book_narrator', function (Blueprint $table) {
                if (!$this->indexExists('book_narrator', 'idx_book_narrator_composite')) {
                    $table->index(['book_id', 'narrator_id'], 'idx_book_narrator_composite');
                }
                if (!$this->indexExists('book_narrator', 'idx_book_narrator_narrator_id')) {
                    $table->index('narrator_id', 'idx_book_narrator_narrator_id');
                }
            });
        }

        // Book progress indexes (renamed from reading_progress)
        if (Schema::hasTable('book_progress')) {
            Schema::table('book_progress', function (Blueprint $table) {
                if (!$this->indexExists('book_progress', 'idx_book_progress_user_book')) {
                    $table->index(['user_id', 'book_id'], 'idx_book_progress_user_book');
                }
                if (!$this->indexExists('book_progress', 'idx_book_progress_updated')) {
                    $table->index('updated_at', 'idx_book_progress_updated');
                }
            });
        }
    }

    /**
     * Check if index exists on a table
     */
    private function indexExists(string $tableName, string $indexName): bool
    {
        try {
            $indexes = collect(DB::select("SHOW INDEX FROM {$tableName} WHERE Key_name = ?", [$indexName]));
            return $indexes->isNotEmpty();
        } catch (\Exception $e) {
            // Fallback for drivers that don't support SHOW INDEX or if table doesn't exist yet
            return false;
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('authors')) {
            Schema::table('authors', function (Blueprint $table) {
                if ($this->indexExists('authors', 'idx_authors_name')) {
                    $table->dropIndex('idx_authors_name');
                }
                if ($this->indexExists('authors', 'idx_authors_created')) {
                    $table->dropIndex('idx_authors_created');
                }
            });
        }

        if (Schema::hasTable('series')) {
            Schema::table('series', function (Blueprint $table) {
                if ($this->indexExists('series', 'idx_series_name')) {
                    $table->dropIndex('idx_series_name');
                }
                if ($this->indexExists('series', 'idx_series_created')) {
                    $table->dropIndex('idx_series_created');
                }
            });
        }

        if (Schema::hasTable('genres')) {
            Schema::table('genres', function (Blueprint $table) {
                if ($this->indexExists('genres', 'idx_genres_name')) {
                    $table->dropIndex('idx_genres_name');
                }
                if ($this->indexExists('genres', 'idx_genres_created')) {
                    $table->dropIndex('idx_genres_created');
                }
            });
        }

        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                if ($this->indexExists('users', 'idx_users_email')) {
                    $table->dropIndex('idx_users_email');
                }
                if (Schema::hasColumn('users', 'role') && $this->indexExists('users', 'idx_users_role_created')) {
                    $table->dropIndex('idx_users_role_created');
                }
                if (Schema::hasColumn('users', 'last_login_at') && $this->indexExists('users', 'idx_users_last_login')) {
                    $table->dropIndex('idx_users_last_login');
                }
            });
        }

        if (Schema::hasTable('author_book')) {
            Schema::table('author_book', function (Blueprint $table) {
                if ($this->indexExists('author_book', 'idx_author_book_composite')) {
                    $table->dropIndex('idx_author_book_composite');
                }
                if ($this->indexExists('author_book', 'idx_author_book_book_id')) {
                    $table->dropIndex('idx_author_book_book_id');
                }
            });
        }

        if (Schema::hasTable('book_series')) {
            Schema::table('book_series', function (Blueprint $table) {
                if ($this->indexExists('book_series', 'idx_book_series_composite')) {
                    $table->dropIndex('idx_book_series_composite');
                }
                if ($this->indexExists('book_series', 'idx_book_series_series_id')) {
                    $table->dropIndex('idx_book_series_series_id');
                }
                if (Schema::hasColumn('book_series', 'series_number') && $this->indexExists('book_series', 'idx_book_series_number')) {
                    $table->dropIndex('idx_book_series_number');
                }
            });
        }

        if (Schema::hasTable('book_genre')) {
            Schema::table('book_genre', function (Blueprint $table) {
                if ($this->indexExists('book_genre', 'idx_book_genre_composite')) {
                    $table->dropIndex('idx_book_genre_composite');
                }
                if ($this->indexExists('book_genre', 'idx_book_genre_genre_id')) {
                    $table->dropIndex('idx_book_genre_genre_id');
                }
            });
        }

        if (Schema::hasTable('book_narrator')) {
            Schema::table('book_narrator', function (Blueprint $table) {
                if ($this->indexExists('book_narrator', 'idx_book_narrator_composite')) {
                    $table->dropIndex('idx_book_narrator_composite');
                }
                if ($this->indexExists('book_narrator', 'idx_book_narrator_narrator_id')) {
                    $table->dropIndex('idx_book_narrator_narrator_id');
                }
            });
        }

        if (Schema::hasTable('book_progress')) {
            Schema::table('book_progress', function (Blueprint $table) {
                if ($this->indexExists('book_progress', 'idx_book_progress_user_book')) {
                    $table->dropIndex('idx_book_progress_user_book');
                }
                if ($this->indexExists('book_progress', 'idx_book_progress_updated')) {
                    $table->dropIndex('idx_book_progress_updated');
                }
            });
        }
    }
};
