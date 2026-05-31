<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DatabaseSyncService
{
    protected ?\Closure $conflictResolver = null;

    /**
     * Set a callback to resolve conflicts interactively.
     * The callback receives (string $type, array $sourceData, array $targetData)
     * and should return string action: 'overwrite', 'skip', 'merge', etc.
     */
    public function setConflictResolver(callable $callback): void
    {
        $this->conflictResolver = $callback;
    }

    /**
     * Sync a table from source to target.
     * WARNING: This truncates the target table!
     *
     * @param string $table
     * @param Connection $source
     * @param Connection $target
     * @return int Number of rows synced
     */
    public function syncTable(string $table, Connection $source, Connection $target, bool $confirmed = false): int
    {
        if (! $confirmed) {
            throw new \RuntimeException('Refusing to sync table without explicit destructive-operation confirmation.');
        }

        $driver = $target->getDriverName();

        Log::warning('DatabaseSyncService truncating target table for sync', [
            'table' => $table,
            'target_connection' => $target->getName(),
            'target_database' => $target->getDatabaseName(),
        ]);

        try {
            if ($driver === 'sqlite') {
                $target->statement('PRAGMA foreign_keys = OFF;');
            } elseif ($driver === 'mysql') {
                $target->statement('SET FOREIGN_KEY_CHECKS=0;');
            }

            $target->table($table)->truncate();
        } finally {
            if ($driver === 'sqlite') {
                $target->statement('PRAGMA foreign_keys = ON;');
            } elseif ($driver === 'mysql') {
                $target->statement('SET FOREIGN_KEY_CHECKS=1;');
            }
        }

        // Chunking to handle large tables
        $count = 0;
        $source->table($table)->orderBy('id')->chunk(1000, function ($rows) use ($target, $table, &$count) {
            $data = [];
            foreach ($rows as $row) {
                $data[] = (array) $row;
            }
            $target->table($table)->insert($data);
            $count += count($data);
        });

        return $count;
    }

    /**
     * Sync a specific book and its relationships.
     */
    public function syncBook(int $sourceBookId, Connection $source, Connection $target): bool
    {
        // 1. Fetch Source Book
        $sourceBook = $source->table('books')->find($sourceBookId);
        if (!$sourceBook) {
            Log::error("Book ID {$sourceBookId} not found in source.");
            return false;
        }

        $sourceBookData = (array) $sourceBook;

        // 2. Check Target Book
        $targetBook = $target->table('books')->find($sourceBookId);

        if ($targetBook) {
            $targetBookData = (array) $targetBook;
            // Detect diff (simple comparison for now)
            $diff = array_diff_assoc($sourceBookData, $targetBookData);

            if (!empty($diff) && $this->conflictResolver) {
                $action = ($this->conflictResolver)('book_conflict', $sourceBookData, $targetBookData);
                if ($action === 'skip') {
                    return true;
                }
                // 'overwrite' continues below
            }
        }

        // 3. Upsert Book
        // Remove timestamps from update array if we want to preserve them exactly,
        // using insert/updateOrInsert with specific ID ensures we keep it.
        $target->table('books')->updateOrInsert(['id' => $sourceBookId], $sourceBookData);

        // 4. Sync Relationships (Simple approach: Authors)
        // This requires fetching pivots and related entities.
        // For 'authors', we match by name.
        $this->syncBookAuthors($sourceBookId, $source, $target);

        // TODO: Other relations (Genres, Series, etc) follow same pattern.

        return true;
    }

    protected function syncBookAuthors(int $bookId, Connection $source, Connection $target): void
    {
        // Get source authors
        $sourcePivot = $source->table('author_book')->where('book_id', $bookId)->get();
        if ($sourcePivot->isEmpty()) {
            return;
        }

        $targetAuthorIds = [];

        foreach ($sourcePivot as $pivot) {
            $authorId = $pivot->author_id;
            $sourceAuthorData = $source->table('authors')->find($authorId);

            if (!$sourceAuthorData) {
                continue;
            }

            $sourceAuthor = (object) $sourceAuthorData;

            // Find matching author in target by Name
            $targetAuthor = $target->table('authors')->where('name', $sourceAuthor->name)->first();

            if (!$targetAuthor) {
                // Create new author in target
                // We let DB assign new ID to avoid collisions, or we could force ID if we sync 'authors' table fully first.
                // Here we assume partial sync, so we create with new ID.
                $newId = $target->table('authors')->insertGetId([
                    'name' => $sourceAuthor->name,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $targetAuthorIds[] = $newId;
            } else {
                $targetAuthorIds[] = $targetAuthor->id;
            }
        }

        // Sync Pivot
        $target->table('author_book')->where('book_id', $bookId)->delete();
        foreach ($targetAuthorIds as $aid) {
            $target->table('author_book')->insert([
                'book_id' => $bookId,
                'author_id' => $aid,
            ]);
        }
    }
}
