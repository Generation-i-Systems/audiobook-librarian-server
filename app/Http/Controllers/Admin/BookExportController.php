<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\DocumentStoreServiceInterface;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class BookExportController extends Controller
{
    protected DocumentStoreServiceInterface $documentStoreService;

    public function __construct(DocumentStoreServiceInterface $documentStoreService)
    {
        $this->documentStoreService = $documentStoreService;
    }

    public function download($id)
    {
        $documentStore = $this->documentStoreService;
        $book = $documentStore->getBook($id);
        $directoryPath = $book['directoryPath'];

        try {
            if (!$directoryPath || !Storage::disk('books')->exists($directoryPath)) {
                abort(404, 'Book directory not found.');
            }

            $files = Storage::disk('books')->files($directoryPath);
        } catch (\League\Flysystem\UnableToCreateDirectory $e) {
            $bookStoragePath = config('filesystems.disks.books.root');
            throw new \RuntimeException(
                "Book storage directory is not accessible. The configured path '{$bookStoragePath}' does not exist or cannot be created. " .
                "Please check that the BOOK_STORAGE_PATH environment variable points to a valid, accessible directory."
            );
        }

        if (empty($files)) {
            abort(404, 'No files found for this book.');
        }

        $zipFileName = str_replace(' ', '_', $book['title']) . '.zip';  // Sanitize filename
        $zipPath = storage_path(
            'app/public/temp/' . $zipFileName
        );  // Temporary storage

        $zip = new ZipArchive();

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            abort(500, 'Failed to create zip archive.');
        }

        foreach ($files as $file) {
            $zip->addFile(Storage::disk('books')->path($file), basename($file));
        }

        $zip->close();

        // Return the zip file as a download
        return response()
            ->download($zipPath, $zipFileName)
            ->deleteFileAfterSend(true); // Delete the temp zip file after sending.
    }
}
