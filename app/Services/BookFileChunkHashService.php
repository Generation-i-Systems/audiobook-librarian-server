<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Book;
use App\Models\BookFileChunkHash;
use Illuminate\Support\Facades\Storage;

class BookFileChunkHashService
{
    public const DOWNLOAD_CHUNK_SIZE = 8 * 1024 * 1024;

    private const AUDIO_EXTENSIONS = ['mp3', 'm4a', 'wav', 'aac', 'ogg', 'flac', 'm4b'];

    /**
     * @return array{generated: int, cached: int, skipped: int, missing: int}
     */
    public function cacheBook(Book $book, bool $force = false): array
    {
        $stats = [
            'generated' => 0,
            'cached' => 0,
            'skipped' => 0,
            'missing' => 0,
        ];

        if ($book->source === 'librivox' || $book->directory_path === null || $book->directory_path === '') {
            $stats['skipped']++;
            return $stats;
        }

        $directoryPath = $book->directory_path;
        if (! Storage::disk('books')->exists($directoryPath) && empty(Storage::disk('books')->allFiles($directoryPath))) {
            $stats['missing']++;
            return $stats;
        }

        foreach (Storage::disk('books')->allFiles($directoryPath) as $filePath) {
            $result = $this->getOrGenerate((int) $book->id, $filePath, Storage::disk('books')->path($filePath), $force);
            if ($result === null) {
                $stats['missing']++;
                continue;
            }

            $result['generated'] ? $stats['generated']++ : $stats['cached']++;
        }

        return $stats;
    }

    /**
     * @return array{record: BookFileChunkHash, generated: bool}|null
     */
    public function getOrGenerate(int $bookId, string $filePath, string $fullPath, bool $force = false): ?array
    {
        $metadata = $this->fileMetadata($fullPath);
        if ($metadata === null) {
            return null;
        }

        $pathHash = hash('sha256', $filePath);
        $existing = BookFileChunkHash::query()
            ->where('book_id', $bookId)
            ->where('file_path_hash', $pathHash)
            ->where('chunk_size', self::DOWNLOAD_CHUNK_SIZE)
            ->first();

        if (! $force && $existing !== null && $this->isFresh($existing, $filePath, $metadata)) {
            return [
                'record' => $existing,
                'generated' => false,
            ];
        }

        $hashes = $this->hashFileChunks($fullPath);
        if ($hashes === null) {
            return null;
        }

        $record = BookFileChunkHash::query()->updateOrCreate(
            [
                'book_id' => $bookId,
                'file_path_hash' => $pathHash,
                'chunk_size' => self::DOWNLOAD_CHUNK_SIZE,
            ],
            [
                'file_path' => $filePath,
                'file_size' => $metadata['size'],
                'file_mtime' => $metadata['mtime'],
                'sha256' => $hashes['sha256'],
                'chunks' => $hashes['chunks'],
                'generated_at' => now(),
            ]
        );

        return [
            'record' => $record,
            'generated' => true,
        ];
    }

    public function isAudioFile(string $filePath): bool
    {
        return in_array(strtolower(pathinfo($filePath, PATHINFO_EXTENSION)), self::AUDIO_EXTENSIONS, true);
    }

    /**
     * @return array{size: int, mtime: int}|null
     */
    private function fileMetadata(string $fullPath): ?array
    {
        clearstatcache(true, $fullPath);

        if (! is_file($fullPath)) {
            return null;
        }

        $size = filesize($fullPath);
        $mtime = filemtime($fullPath);
        if ($size === false || $mtime === false) {
            return null;
        }

        return [
            'size' => $size,
            'mtime' => $mtime,
        ];
    }

    /**
     * @param array{size: int, mtime: int} $metadata
     */
    private function isFresh(BookFileChunkHash $record, string $filePath, array $metadata): bool
    {
        return $record->file_path === $filePath
            && $record->file_size === $metadata['size']
            && $record->file_mtime === $metadata['mtime'];
    }

    /**
     * @return array{sha256: string, chunks: array<int, array{offset: int, size: int, sha256: string}>}|null
     */
    private function hashFileChunks(string $fullPath): ?array
    {
        $handle = fopen($fullPath, 'rb');
        if ($handle === false) {
            return null;
        }

        $chunks = [];
        $offset = 0;
        $fileHash = hash_init('sha256');

        try {
            while (! feof($handle)) {
                $data = fread($handle, self::DOWNLOAD_CHUNK_SIZE);
                if ($data === false || $data === '') {
                    break;
                }

                hash_update($fileHash, $data);
                $size = strlen($data);
                $chunks[] = [
                    'offset' => $offset,
                    'size' => $size,
                    'sha256' => hash('sha256', $data),
                ];
                $offset += $size;
            }
        } finally {
            fclose($handle);
        }

        return [
            'sha256' => hash_final($fileHash),
            'chunks' => $chunks,
        ];
    }
}
