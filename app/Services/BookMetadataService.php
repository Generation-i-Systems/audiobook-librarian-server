<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\DocumentStoreServiceInterface;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;

class BookMetadataService
{
    protected string $localFilename;

    public function __construct(DocumentStoreServiceInterface $documentStoreService)
    {
        $this->localFilename = Config::get('bookparser.local_metadata_filename', 'librarian.json');
    }

    /**
     * Save book metadata using the configured storage method.
     *
     * @param  string  $bookId  Unique identifier for the book
     * @param  string  $directoryPath  Path to the book directory
     * @param  array  $metadata  Metadata to save
     * @return bool True on success, false on failure
     */
    public function saveMetadata(string $bookId, string $directoryPath, array $metadata): bool
    {
        return $this->saveToLocalFile($directoryPath, $metadata);
    }

    /**
     * Load book metadata using the configured storage method.
     *
     * @param  string  $bookId  Unique identifier for the book
     * @param  string  $directoryPath  Path to the book directory
     * @return array Loaded metadata or empty array if not found
     */
    public function loadMetadata(string $bookId, string $directoryPath): array
    {
        return $this->loadFromLocalFile($directoryPath);
    }

    /**
     * Save metadata to a local JSON file in the book directory.
     *
     * @param  string  $directoryPath  Path to the book directory
     * @param  array  $metadata  Metadata to save
     * @return bool True on success, false on failure
     */
    protected function saveToLocalFile(string $directoryPath, array $metadata): bool
    {
        $filePath = rtrim($directoryPath, '/') . '/' . $this->localFilename;

        try {
            $json = json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                throw new \RuntimeException('Failed to encode metadata to JSON');
            }

            $result = file_put_contents($filePath, $json);

            return $result !== false;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Failed to save local metadata file', [
                'path' => $filePath,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Load metadata from a local JSON file in the book directory.
     *
     * @param  string  $directoryPath  Path to the book directory
     * @return array Loaded metadata or empty array if not found
     */
    protected function loadFromLocalFile(string $directoryPath): array
    {
        $filePath = rtrim($directoryPath, '/') . '/' . $this->localFilename;

        if (!file_exists($filePath) || !is_readable($filePath)) {
            return [];
        }

        try {
            $json = file_get_contents($filePath);
            if ($json === false) {
                throw new \RuntimeException('Failed to read metadata file');
            }

            $data = json_decode($json, true);

            return is_array($data) ? $data : [];
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Failed to load local metadata file', [
                'path' => $filePath,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    public function generateBookId(string $directoryPath): string
    {
        $normalized = trim($directoryPath);
        $normalized = str_replace("\\", '/', $normalized);
        $normalized = preg_replace('#/+#', '/', $normalized) ?? $normalized;

        return hash('sha256', $normalized);
    }

    // Firestore support removed; BookMetadataService now saves and loads only from local JSON files.
}
