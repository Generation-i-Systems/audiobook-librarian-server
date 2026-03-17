<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Author;
use App\Models\Book;
use App\Models\Bookmark;
use App\Models\ExternalRead;
use App\Models\Genre;
use App\Models\Job;
use App\Models\Message;
use App\Models\Narrator;
use App\Models\Series;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class GenericDocumentService
{
    public function getDocument(string $collection, string $docId): ?array
    {
        $modelClass = $this->resolveModelClass($collection, true);

        if ($modelClass === null) {
            return null;
        }

        try {
            $instance = $modelClass::find($docId);

            return $instance ? $instance->toArray() : null;
        } catch (\Exception $e) {
            Log::error("Failed to get document from {$collection} (ID: {$docId}): " . $e->getMessage());

            return null;
        }
    }

    public function updateDocument(string $collection, string $id, array $data): bool
    {
        $modelClass = $this->resolveModelClass($collection, false);

        if ($modelClass === null) {
            return false;
        }

        try {
            $instance = $modelClass::findOrFail($id);

            return $instance->update($data);
        } catch (\Exception $e) {
            Log::error("Failed to update document in {$collection} (ID: {$id}): " . $e->getMessage());

            return false;
        }
    }

    private function resolveModelClass(string $collection, bool $includeNarrators): ?string
    {
        $modelMap = [
            'users' => User::class,
            'messages' => Message::class,
            'genres' => Genre::class,
            'authors' => Author::class,
            'series' => Series::class,
            'books' => Book::class,
            'jobs' => Job::class,
            'bookmarks' => Bookmark::class,
            'external_reads' => ExternalRead::class,
        ];

        if ($includeNarrators) {
            $modelMap['narrators'] = Narrator::class;
        }

        return $modelMap[$collection] ?? null;
    }
}
