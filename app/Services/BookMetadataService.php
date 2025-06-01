<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BookMetadataService
{
    protected FirestoreService $firestoreService;
    protected string $storageMethod;
    protected string $localFilename;

    public function __construct(FirestoreService $firestoreService)
    {
        $this->firestoreService = $firestoreService;
        $this->storageMethod = Config::get('bookparser.metadata_storage', 'local');
        $this->localFilename = Config::get('bookparser.local_metadata_filename', 'librarian.json');
    }

    /**
     * Save book metadata using the configured storage method.
     *
     * @param string $bookId Unique identifier for the book
     * @param string $directoryPath Path to the book directory
     * @param array $metadata Metadata to save
     * @return bool True on success, false on failure
     */
    public function saveMetadata(string $bookId, string $directoryPath, array $metadata): bool
    {
        return match ($this->storageMethod) {
            'firestore' => $this->saveToFirestore($bookId, $metadata),
            default => $this->saveToLocalFile($directoryPath, $metadata),
        };
    }

    /**
     * Load book metadata using the configured storage method.
     *
     * @param string $bookId Unique identifier for the book
     * @param string $directoryPath Path to the book directory
     * @return array Loaded metadata or empty array if not found
     */
    public function loadMetadata(string $bookId, string $directoryPath): array
    {
        return match ($this->storageMethod) {
            'firestore' => $this->loadFromFirestore($bookId),
            default => $this->loadFromLocalFile($directoryPath),
        };
    }

    /**
     * Save metadata to a local JSON file in the book directory.
     *
     * @param string $directoryPath Path to the book directory
     * @param array $metadata Metadata to save
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
     * @param string $directoryPath Path to the book directory
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

    /**
     * Save metadata to Firestore.
     *
     * @param string $bookId Unique identifier for the book
     * @param array $metadata Metadata to save
     * @return bool True on success, false on failure
     */
    protected function saveToFirestore(string $bookId, array $metadata): bool
    {
        try {
            // Ensure we have required fields
            $metadata['id'] = $bookId;
            $metadata['updated_at'] = now()->toIso8601String();
            
            // Check if document exists
            $existing = $this->firestoreService->getBook($bookId);
            
            if ($existing) {
                // Update existing document
                $this->firestoreService->updateBook($bookId, $metadata);
            } else {
                // Create new document
                $metadata['created_at'] = $metadata['updated_at'];
                $this->firestoreService->createBook($metadata);
            }
            
            return true;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Failed to save metadata to Firestore', [
                'bookId' => $bookId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Load metadata from Firestore.
     *
     * @param string $bookId Unique identifier for the book
     * @return array Loaded metadata or empty array if not found
     */
    protected function loadFromFirestore(string $bookId): array
    {
        try {
            $book = $this->firestoreService->getBook($bookId);
            return is_array($book) ? $book : [];
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Failed to load metadata from Firestore', [
                'bookId' => $bookId,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Generate a unique book ID based on the directory path.
     * This ensures consistent IDs between local and Firestore storage.
     *
     * @param string $directoryPath
     * @return string
     */
    public function generateBookId(string $directoryPath): string
    {
        // Remove any trailing slashes and normalize the path
        $normalizedPath = rtrim(str_replace('\\', '/', $directoryPath), '/');
        
        // Create a hash of the normalized path
        return hash('sha256', $normalizedPath);
    }
}
