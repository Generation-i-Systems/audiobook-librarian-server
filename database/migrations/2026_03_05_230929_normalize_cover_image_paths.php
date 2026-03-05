<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    /**
     * Normalize cover_image paths to just filenames.
     *
     * cover_image should store only the filename (e.g., "cover.jpg"),
     * and is resolved relative to the book's directory_path at runtime.
     * This migration converts existing full paths to just filenames.
     */
    public function up(): void
    {
        // Use database-agnostic approach - fetch and update row by row
        // This works for both MySQL and SQLite (tests)
        $books = DB::table('books')
            ->whereNotNull('cover_image')
            ->where('cover_image', '!=', '')
            ->where('cover_image', 'not like', 'http://%')
            ->where('cover_image', 'not like', 'https://%')
            ->where('cover_image', 'like', '%/%')
            ->get(['id', 'cover_image']);

        foreach ($books as $book) {
            $filename = basename($book->cover_image);
            DB::table('books')
                ->where('id', $book->id)
                ->update(['cover_image' => $filename]);
        }
    }

    /**
     * Reverse the migrations.
     *
     * Note: This cannot fully reverse the operation because we don't store
     * the original paths. The down method is a no-op for safety.
     */
    public function down(): void
    {
        // No-op: cannot restore original paths
    }
};
