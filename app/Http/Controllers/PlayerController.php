<?php

namespace App\Http\Controllers;

use App\Contracts\DocumentStoreServiceInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class PlayerController extends Controller
{
    protected DocumentStoreServiceInterface $documentStore;

    public function __construct(DocumentStoreServiceInterface $documentStore)
    {
        $this->documentStore = $documentStore;
    }

    /**
     * Show the web player for a book
     */
    public function show(string $id)
    {
        try {
            $book = $this->documentStore->getBook($id);

            if (!$book) {
                abort(404, 'Book not found');
            }

            // Get audio files
            $audioFiles = $this->getAudioFiles($book);

            if (empty($audioFiles)) {
                return redirect()->route('books.show', $id)
                    ->with('error', 'No audio files found for this book');
            }

            // Get user progress if authenticated
            $progress = null;
            if (Auth::check()) {
                $progress = $this->documentStore->getProgress(Auth::id(), $id);
            }

            return view('player.show', compact('book', 'audioFiles', 'progress'));
        } catch (\Exception $e) {
            Log::error('Player error', [
                'book_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return redirect()->route('books.index')
                ->with('error', 'Unable to load player');
        }
    }

    /**
     * Get audio files for a book
     */
    private function getAudioFiles(array $book): array
    {
        $storagePath = rtrim(config('filesystems.disks.books.root') ?? config('app.book_root'), '/');
        $directoryPath = $book['directoryPath'] ?? $book['path'] ?? null;

        if (!$directoryPath) {
            return [];
        }

        $fullPath = $storagePath . '/' . ltrim($directoryPath, '/');

        if (!is_dir($fullPath)) {
            return [];
        }

        $files = [];
        $items = scandir($fullPath);

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $itemPath = $fullPath . '/' . $item;

            if (is_file($itemPath) && preg_match('/\.(m4b|m4a|mp3)$/i', $item)) {
                $files[] = [
                    'name' => $item,
                    'path' => $directoryPath . '/' . $item,
                    'size' => filesize($itemPath),
                ];
            }
        }

        // Sort files naturally
        usort($files, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));

        return $files;
    }
}
