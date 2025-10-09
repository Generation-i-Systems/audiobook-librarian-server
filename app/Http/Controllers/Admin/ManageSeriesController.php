<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\DocumentStoreServiceInterface;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ManageSeriesController extends Controller
{
    public function __construct(
        private DocumentStoreServiceInterface $documentStore
    ) {
    }

    /**
     * Show the series management page
     */
    public function index(Request $request)
    {
        $search = $request->get('search', '');
        
        // Get all books grouped by series
        $allBooks = $this->documentStore->getAllBooks();
        
        // Group books by their directory path prefix (likely same book split across directories)
        $potentialMerges = [];
        $seriesGroups = [];
        
        foreach ($allBooks as $book) {
            $seriesName = '';
            if (!empty($book['series'])) {
                if (is_array($book['series'])) {
                    $seriesName = array_key_first($book['series']);
                } else {
                    $seriesName = $book['series'];
                }
            }
            
            if (empty($seriesName)) {
                continue;
            }
            
            // Filter by search if provided
            if ($search && stripos($seriesName, $search) === false) {
                continue;
            }
            
            if (!isset($seriesGroups[$seriesName])) {
                $seriesGroups[$seriesName] = [];
            }
            
            $seriesGroups[$seriesName][] = $book;
        }
        
        // Sort series by name
        ksort($seriesGroups);
        
        // Identify potential merges (books with similar directory paths)
        foreach ($seriesGroups as $seriesName => $books) {
            if (count($books) <= 1) {
                continue;
            }
            
            // Group by common directory prefix
            $pathGroups = [];
            foreach ($books as $book) {
                $path = $book['directoryPath'] ?? '';
                // Get parent directory (remove last segment)
                $parts = explode('/', $path);
                array_pop($parts);
                $parentPath = implode('/', $parts);
                
                if (!isset($pathGroups[$parentPath])) {
                    $pathGroups[$parentPath] = [];
                }
                $pathGroups[$parentPath][] = $book;
            }
            
            // If multiple books share the same parent directory, they might need merging
            foreach ($pathGroups as $parentPath => $groupBooks) {
                if (count($groupBooks) > 1) {
                    $potentialMerges[] = [
                        'series' => $seriesName,
                        'parentPath' => $parentPath,
                        'books' => $groupBooks,
                    ];
                }
            }
        }
        
        return view('admin.series.manage', [
            'seriesGroups' => $seriesGroups,
            'potentialMerges' => $potentialMerges,
            'search' => $search,
        ]);
    }

    /**
     * Merge multiple book entries into one
     */
    public function merge(Request $request)
    {
        $bookIds = $request->input('book_ids', []);
        $primaryBookId = $request->input('primary_book_id');
        $flattenDirectories = $request->input('flatten_directories', false);
        
        if (count($bookIds) < 2) {
            return back()->with('error', 'Please select at least 2 books to merge');
        }
        
        if (!$primaryBookId || !in_array($primaryBookId, $bookIds)) {
            return back()->with('error', 'Please select a primary book');
        }
        
        try {
            // Get all books to merge
            $books = [];
            foreach ($bookIds as $bookId) {
                $book = $this->documentStore->getBookById($bookId);
                if ($book) {
                    $books[] = $book;
                }
            }
            
            if (count($books) < 2) {
                return back()->with('error', 'Could not find all selected books');
            }
            
            // Find the primary book
            $primaryBook = null;
            $secondaryBooks = [];
            foreach ($books as $book) {
                if ($book['_id'] == $primaryBookId) {
                    $primaryBook = $book;
                } else {
                    $secondaryBooks[] = $book;
                }
            }
            
            if (!$primaryBook) {
                return back()->with('error', 'Primary book not found');
            }
            
            // If flatten directories is enabled, move files
            if ($flattenDirectories) {
                $this->flattenDirectories($primaryBook, $secondaryBooks);
            }
            
            // Delete secondary books from database
            foreach ($secondaryBooks as $book) {
                $this->documentStore->deleteBook($book['_id']);
            }
            
            return redirect()
                ->route('admin.series.manage')
                ->with('success', 'Successfully merged ' . count($secondaryBooks) . ' books into one');
                
        } catch (\Exception $e) {
            Log::error('Error merging books: ' . $e->getMessage());
            return back()->with('error', 'Error merging books: ' . $e->getMessage());
        }
    }

    /**
     * Flatten directories by moving files from subdirectories to primary directory
     */
    private function flattenDirectories($primaryBook, $secondaryBooks)
    {
        $storageRoot = rtrim(env('BOOK_STORAGE_PATH'), '/');
        $primaryPath = $storageRoot . '/' . $primaryBook['directoryPath'];
        
        if (!is_dir($primaryPath)) {
            throw new \Exception("Primary book directory not found: {$primaryPath}");
        }
        
        foreach ($secondaryBooks as $book) {
            $secondaryPath = $storageRoot . '/' . $book['directoryPath'];
            
            if (!is_dir($secondaryPath)) {
                Log::warning("Secondary book directory not found: {$secondaryPath}");
                continue;
            }
            
            // Move all audio files from secondary to primary directory
            $files = glob($secondaryPath . '/*.{m4b,mp3,m4a}', GLOB_BRACE);
            foreach ($files as $file) {
                $filename = basename($file);
                $destination = $primaryPath . '/' . $filename;
                
                // If file exists, rename with a suffix
                if (file_exists($destination)) {
                    $pathInfo = pathinfo($filename);
                    $counter = 1;
                    do {
                        $newFilename = $pathInfo['filename'] . '_' . $counter . '.' . $pathInfo['extension'];
                        $destination = $primaryPath . '/' . $newFilename;
                        $counter++;
                    } while (file_exists($destination));
                }
                
                if (!rename($file, $destination)) {
                    Log::error("Failed to move file: {$file} to {$destination}");
                }
            }
            
            // Try to remove the now-empty directory
            if (count(glob($secondaryPath . '/*')) === 0) {
                rmdir($secondaryPath);
            }
        }
    }

    /**
     * Rename a series
     */
    public function rename(Request $request)
    {
        $oldName = $request->input('old_name');
        $newName = $request->input('new_name');
        
        if (empty($oldName) || empty($newName)) {
            return back()->with('error', 'Both old and new series names are required');
        }
        
        try {
            // Get all books in the series
            $allBooks = $this->documentStore->getAllBooks();
            $updated = 0;
            
            foreach ($allBooks as $book) {
                $seriesName = '';
                $seriesNumber = null;
                
                if (!empty($book['series'])) {
                    if (is_array($book['series'])) {
                        $seriesName = array_key_first($book['series']);
                        $seriesNumber = $book['series'][$seriesName] ?? null;
                    } else {
                        $seriesName = $book['series'];
                        $seriesNumber = $book['seriesNumber'] ?? null;
                    }
                }
                
                if ($seriesName === $oldName) {
                    // Update the series name
                    $book['series'] = [$newName => $seriesNumber];
                    $book['seriesName'] = $newName;
                    
                    $this->documentStore->updateBook($book['_id'], $book);
                    $updated++;
                }
            }
            
            return back()->with('success', "Renamed series '{$oldName}' to '{$newName}' for {$updated} books");
            
        } catch (\Exception $e) {
            Log::error('Error renaming series: ' . $e->getMessage());
            return back()->with('error', 'Error renaming series: ' . $e->getMessage());
        }
    }
}
