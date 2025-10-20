<?php

/**
 * Command script to:
 * - Remove duplicate books by directoryPath (keep most recently edited or most fields set)
 * - Flag books for review based on several criteria (see below)
 *
 * Usage: php scripts/remove_duplicate_and_flag_books.php
 */

use MongoDB\Client;

require __DIR__ . '/../vendor/autoload.php';

// CONFIG
$mongoUri = getenv('MONGO_URI') ?: 'mongodb://localhost:27017';
$dbName = getenv('DB_DATABASE') ?: 'audiobook_librarian';
$collectionName = 'books';

$client = new Client($mongoUri);
$books = $client->$dbName->$collectionName;

$allBooks = $books->find([])->toArray();

// 1. Remove duplicates by directoryPath
$byDir = [];
foreach ($allBooks as $book) {
    $dir = $book['directoryPath'] ?? null;
    if (!$dir) {
        continue;
    }
    $byDir[$dir][] = $book;
}
$toDelete = [];
foreach ($byDir as $dir => $group) {
    if (count($group) > 1) {
        usort($group, function ($a, $b) {
            $aFields = count(array_filter($a, fn ($v) => $v && $v !== '' && $v !== []));
            $bFields = count(array_filter($b, fn ($v) => $v && $v !== '' && $v !== []));
            $aUpdated = isset($a['updatedAt']) ? (is_object($a['updatedAt']) ? $a['updatedAt']->toDateTime()->getTimestamp() : strtotime($a['updatedAt'])) : 0;
            $bUpdated = isset($b['updatedAt']) ? (is_object($b['updatedAt']) ? $b['updatedAt']->toDateTime()->getTimestamp() : strtotime($b['updatedAt'])) : 0;
            if ($aUpdated !== $bUpdated) {
                return $bUpdated <=> $aUpdated;
            }

            return $bFields <=> $aFields;
        });
        // Keep first, delete rest
        for ($i = 1; $i < count($group); $i++) {
            $toDelete[] = $group[$i]['_id'];
        }
    }
}
if ($toDelete) {
    $books->deleteMany(['_id' => ['$in' => $toDelete]]);
    echo 'Deleted ' . count($toDelete) . " duplicate books by directoryPath\n";
}

// 2. Flag books for review
function parseDirectoryPath($dir)
{
    // Expect: genre/author/series/number/title or genre/author/title
    $parts = explode('/', $dir);
    if (count($parts) >= 5) {
        return [
            'genre' => $parts[0],
            'author' => $parts[1],
            'series' => $parts[2],
            'number' => $parts[3],
            'title' => $parts[4],
        ];
    } elseif (count($parts) >= 3) {
        return [
            'genre' => $parts[0],
            'author' => $parts[1],
            'title' => $parts[count($parts) - 1],
        ];
    }

    return [];
}

$needsReview = [];
foreach ($allBooks as $book) {
    $reasons = [];
    $dir = $book['directoryPath'] ?? null;
    if ($dir) {
        $parsed = parseDirectoryPath($dir);
        // Compare parsed fields
        if (isset($parsed['genre'], $book['genre']) && !in_array($parsed['genre'], (array) $book['genre'])) {
            $reasons[] = 'directoryPath genre mismatch';
        }
        if (isset($parsed['author'], $book['author']) && !in_array($parsed['author'], (array) $book['author'])) {
            $reasons[] = 'directoryPath author mismatch';
        }
        if (isset($parsed['series'], $book['series'])) {
            $seriesNames = array_map(fn ($s) => is_array($s) ? ($s['seriesName'] ?? $s['name'] ?? null) : $s, (array) $book['series']);
            if (!in_array($parsed['series'], $seriesNames)) {
                $reasons[] = 'directoryPath series mismatch';
            }
        }
        if (isset($parsed['title'], $book['title']) && $parsed['title'] !== $book['title']) {
            $reasons[] = 'directoryPath title mismatch';
        }
    }
    // No cover
    if (empty($book['coverImage'])) {
        $coverUrl = $book['coverImageUrl'] ?? $book['googleBooksCoverImageUrl'] ?? null;
        $hasLocal = false;
        if ($dir) {
            $storageRoot = rtrim(getenv('BOOK_STORAGE_PATH') ?: '/media/lyra_data1/audiobooks/books', '/');
            $coverGlob = glob($storageRoot . '/' . ltrim($dir, '/') . '/cover*.*');
            $hasLocal = !empty($coverGlob);
        }
        if ($coverUrl) {
            $reasons[] = 'missing cover but coverImageUrl present';
        } elseif ($hasLocal) {
            $reasons[] = 'missing cover but local image present';
        } else {
            $reasons[] = 'missing cover';
        }
    }
    // Series name matches author name
    if (!empty($book['series']) && !empty($book['author'])) {
        $seriesNames = array_map(fn ($s) => is_array($s) ? ($s['seriesName'] ?? $s['name'] ?? null) : $s, (array) $book['series']);
        $authors = (array) $book['author'];
        foreach ($seriesNames as $sn) {
            if ($sn && in_array($sn, $authors)) {
                $reasons[] = 'series name matches author name';
                break;
            }
        }
    }
    // Series name but no number
    if (!empty($book['series'])) {
        foreach ((array) $book['series'] as $s) {
            $name = is_array($s) ? ($s['seriesName'] ?? $s['name'] ?? null) : $s;
            $number = is_array($s) ? ($s['number'] ?? null) : null;
            if ($name && !$number) {
                $reasons[] = 'series with no number';
            }
        }
    }
    if ($reasons) {
        $needsReview[] = [
            '_id' => $book['_id'],
            'needsReviewReasons' => $reasons,
        ];
    }
}
foreach ($needsReview as $entry) {
    $books->updateOne(['_id' => $entry['_id']], [
        '$set' => [
            'needsReview' => true,
            'needsReviewReasons' => $entry['needsReviewReasons'],
        ],
    ]);
}
echo 'Flagged ' . count($needsReview) . " books for review\n";
