<?php

declare(strict_types=1);

namespace App\Services\Imports;

use App\Models\Imports\ImportDraft;
use App\Models\Imports\ImportDraftFile;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Private on-disk staging for imports.v1 resumable uploads.
 *
 * Paths are derived only from the draft's public id and the file's database id,
 * never from anything the client sent. The database offset is the source of truth:
 * an append first truncates the staged file to the confirmed offset, so bytes left
 * behind by a crash between the write and the commit are overwritten.
 */
class ImportStagingStore
{
    public function root(): string
    {
        return rtrim((string) config('import_drafts.staging_root'), '/');
    }

    public function relativePath(ImportDraft $draft, ImportDraftFile $file): string
    {
        return $this->draftDirectoryName($draft->public_id) . '/' . $file->id . '.part';
    }

    public function absolutePath(string $relativePath): string
    {
        if (preg_match('#^imp_[A-Za-z0-9]+/[0-9]+\.part$#', $relativePath) !== 1) {
            throw new RuntimeException('Refusing a staging path outside the staging layout.');
        }

        return $this->root() . '/' . $relativePath;
    }

    /**
     * Where an audio sample the client sent for an evidence request is kept until interpretation has used it.
     */
    public function evidencePath(string $draftPublicId, string $requestId): string
    {
        if (preg_match('/^ev_[A-Za-z0-9]+$/', $requestId) !== 1) {
            throw new RuntimeException('Refusing an evidence path for an invalid request id.');
        }

        return $this->root() . '/' . $this->draftDirectoryName($draftPublicId) . '/evidence/' . $requestId . '.bin';
    }

    public function writeEvidence(string $draftPublicId, string $requestId, string $bytes): string
    {
        $path = $this->evidencePath($draftPublicId, $requestId);
        File::ensureDirectoryExists(dirname($path), 0755);
        File::put($path, $bytes, true);

        return $path;
    }

    public function deleteEvidence(string $draftPublicId, string $requestId): void
    {
        File::delete($this->evidencePath($draftPublicId, $requestId));
    }

    /**
     * Copies a request body into a temporary stream, refusing more than $maxBytes.
     *
     * @param resource $body
     * @return array{stream: resource, bytes: int}
     */
    public function bufferBody($body, int $maxBytes): array
    {
        $buffer = fopen('php://temp/maxmemory:1048576', 'w+b');
        if ($buffer === false) {
            throw new RuntimeException('Could not open an upload buffer.');
        }
        $copied = stream_copy_to_stream($body, $buffer, $maxBytes + 1);
        if ($copied === false) {
            fclose($buffer);
            throw new RuntimeException('Could not read the upload body.');
        }
        if ($copied > $maxBytes) {
            fclose($buffer);
            throw new ImportApiException(
                413,
                'chunk_too_large',
                'This upload chunk is larger than the server accepts.',
                ['max_chunk_bytes' => $maxBytes],
                true
            );
        }
        rewind($buffer);

        return ['stream' => $buffer, 'bytes' => $copied];
    }

    public function size(string $relativePath): ?int
    {
        $path = $this->absolutePath($relativePath);
        clearstatcache(true, $path);

        return is_file($path) ? (int) filesize($path) : null;
    }

    /**
     * Writes $chunk at $offset, discarding anything beyond $offset first.
     *
     * @param resource $chunk
     */
    public function append(string $relativePath, int $offset, $chunk, int $bytes): void
    {
        $path = $this->absolutePath($relativePath);
        $this->assertWritableLocation($path);
        File::ensureDirectoryExists(dirname($path), 0750);
        $handle = fopen($path, 'c+b');
        if ($handle === false) {
            throw new RuntimeException('Could not open a staged upload.');
        }
        try {
            if (!ftruncate($handle, $offset) || fseek($handle, $offset) !== 0) {
                throw new RuntimeException('Could not position a staged upload.');
            }
            $written = stream_copy_to_stream($chunk, $handle, $bytes);
            if ($written !== $bytes || !fflush($handle)) {
                throw new RuntimeException('Could not write a staged upload.');
            }
        } finally {
            fclose($handle);
        }
    }

    public function createEmpty(string $relativePath): void
    {
        $path = $this->absolutePath($relativePath);
        $this->assertWritableLocation($path);
        File::ensureDirectoryExists(dirname($path), 0750);
        if (file_put_contents($path, '') === false) {
            throw new RuntimeException('Could not create a staged file.');
        }
    }

    public function sha256(string $relativePath): ?string
    {
        $path = $this->absolutePath($relativePath);

        if (!is_file($path)) {
            return null;
        }
        $hash = hash_file('sha256', $path);

        return $hash === false ? null : $hash;
    }

    public function deleteFile(string $relativePath): void
    {
        $path = $this->absolutePath($relativePath);
        if (is_file($path)) {
            unlink($path);
        }
    }

    public function deleteDraft(string $draftPublicId): bool
    {
        $directory = $this->root() . '/' . $this->draftDirectoryName($draftPublicId);

        return File::isDirectory($directory) && File::deleteDirectory($directory);
    }

    /**
     * Draft public ids that currently have a staging directory.
     *
     * @return array<int, string>
     */
    public function stagedDraftIds(): array
    {
        if (!File::isDirectory($this->root())) {
            return [];
        }

        return array_values(array_filter(
            array_map('basename', File::directories($this->root())),
            static fn (string $name): bool => preg_match('/^imp_[A-Za-z0-9]+$/', $name) === 1
        ));
    }

    private function draftDirectoryName(string $draftPublicId): string
    {
        if (preg_match('/^imp_[A-Za-z0-9]+$/', $draftPublicId) !== 1) {
            throw new RuntimeException('Refusing a staging directory for an invalid draft id.');
        }

        return $draftPublicId;
    }

    private function assertWritableLocation(string $path): void
    {
        $directory = dirname($path);
        while (!is_dir($directory) && dirname($directory) !== $directory) {
            $directory = dirname($directory);
        }
        if (!is_writable($directory)) {
            throw new ImportApiException(
                507,
                'staging_unwritable',
                'The library cannot save uploaded books right now. Ask its owner to check import storage permissions.'
            );
        }
    }
}
