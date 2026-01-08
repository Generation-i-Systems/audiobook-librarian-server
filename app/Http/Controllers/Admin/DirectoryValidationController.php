<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ValidateBookDirectoriesJob;
use App\Models\Book;
use App\Services\BookDeletionService;
use App\Services\DirectoryMatchingService;
use App\Services\SourceTrashService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

class DirectoryValidationController extends Controller
{
    protected DirectoryMatchingService $matchingService;
    protected SourceTrashService $trashService;
    protected BookDeletionService $bookDeletionService;

    public function __construct(
        DirectoryMatchingService $matchingService,
        SourceTrashService $trashService,
        BookDeletionService $bookDeletionService
    ) {
        $this->matchingService = $matchingService;
        $this->trashService = $trashService;
        $this->bookDeletionService = $bookDeletionService;
    }

    public function index(Request $request)
    {
        // Get validation results from cache
        $validationResults = Cache::get('book_validation_results', [
            'missing_directories' => 0,
            'orphaned_directories' => 0,
            'last_scan' => 'Never',
        ]);

        $orphanedDirs = Cache::get('orphaned_directories', []);

        // Get books with missing directories (paginated)
        $missingBooks = Book::withMissingDirectories()
            ->with(['authors', 'series', 'genres'])
            ->paginate(50);

        // Run AI matching if requested
        $matches = [];
        if ($request->has('run_matching') && !empty($orphanedDirs)) {
            $matches = $this->matchingService->matchAll($orphanedDirs);
            Cache::put('directory_matches', $matches, now()->addDay());
        } else {
            $matches = Cache::get('directory_matches', []);
        }

        return view('admin.directory-validation', compact(
            'validationResults',
            'missingBooks',
            'orphanedDirs',
            'matches'
        ));
    }

    public function renameDirectory(Request $request)
    {
        $request->validate([
            'book_id' => 'required|exists:books,id',
            'orphaned_path' => 'required|string',
        ]);

        $book = Book::findOrFail($request->book_id);
        $storageRoot = rtrim(config('app.book_root', '/media/lyra_data1/audiobooks/books'), '/');

        $oldPath = $storageRoot . '/' . ltrim($request->orphaned_path, '/');
        $newPath = $storageRoot . '/' . ltrim($book->directory_path, '/');

        if (!File::exists($oldPath)) {
            return back()->with('error', 'Orphaned directory not found');
        }

        if (File::exists($newPath)) {
            return back()->with('error', 'Target directory already exists');
        }

        // Create parent directory if needed
        $parentDir = dirname($newPath);
        if (!File::exists($parentDir)) {
            File::makeDirectory($parentDir, 0755, true);
        }

        // Rename directory
        if (File::move($oldPath, $newPath)) {
            // Update book record
            $book->directory_exists = true;
            $book->directory_last_checked = now();
            $book->save();

            return back()->with('success', 'Directory renamed successfully');
        }

        return back()->with('error', 'Failed to rename directory');
    }

    public function deleteBook(Request $request)
    {
        $request->validate([
            'book_id' => 'required|exists:books,id',
        ]);

        $result = $this->bookDeletionService->moveToTrash($request->book_id, true);

        if ($result['success']) {
            return back()->with('success', 'Book moved to trash successfully');
        }

        return back()->with('error', $result['error'] ?? 'Failed to move book to trash');
    }

    public function importOrphanedDirectory(Request $request)
    {
        $request->validate([
            'orphaned_path' => 'required|string',
        ]);

        // Redirect to import page with path pre-filled
        $returnUrl = route('admin.directory-validation');

        return redirect()->route('admin.books.import')
            ->with('import_path', $request->orphaned_path)
            ->with('return_url', $returnUrl);
    }

    public function deleteOrphanedDirectory(Request $request)
    {
        $request->validate([
            'orphaned_path' => 'required|string',
        ]);

        $fullPath = $request->orphaned_path;

        if (!File::exists($fullPath)) {
            return back()->with('error', 'Directory not found');
        }

        // Move directory to trash instead of deleting
        $trashResult = $this->trashService->movePathToTrash(
            $fullPath,
            'orphaned_directory_cleanup',
            ['cleanup_reason' => 'admin deleted orphaned directory via web interface']
        );

        if ($trashResult) {
            // Update cache by removing just this entry
            $orphanedDirs = Cache::get('orphaned_directories', []);
            $orphanedDirs = array_filter($orphanedDirs, function ($dir) use ($fullPath) {
                return $dir['full_path'] !== $fullPath;
            });
            Cache::put('orphaned_directories', array_values($orphanedDirs), now()->addDay());

            // Update validation results count
            $validationResults = Cache::get('book_validation_results', []);
            if (isset($validationResults['orphaned_directories'])) {
                $validationResults['orphaned_directories']--;
                Cache::put('book_validation_results', $validationResults, now()->addDay());
            }

            return back()->with('success', 'Directory moved to trash successfully');
        }

        return back()->with('error', 'Failed to move directory to trash');
    }

    public function renameOrphanedDirectory(Request $request)
    {
        $request->validate([
            'old_path' => 'required|string',
            'new_path' => 'required|string',
        ]);

        $storageRoot = rtrim(config('app.book_root', '/media/lyra_data1/audiobooks/books'), '/');
        $oldFullPath = $request->old_path;
        $newFullPath = $storageRoot . '/' . ltrim($request->new_path, '/');

        if (!File::exists($oldFullPath)) {
            return back()->with('error', 'Source directory not found');
        }

        if (File::exists($newFullPath)) {
            return back()->with('error', 'Target directory already exists');
        }

        // Create parent directory if needed
        $parentDir = dirname($newFullPath);
        if (!File::exists($parentDir)) {
            File::makeDirectory($parentDir, 0755, true);
        }

        // Rename directory
        if (File::move($oldFullPath, $newFullPath)) {
            // Update cache by modifying just this entry
            $orphanedDirs = Cache::get('orphaned_directories', []);
            foreach ($orphanedDirs as &$dir) {
                if ($dir['full_path'] === $oldFullPath) {
                    $dir['full_path'] = $newFullPath;
                    $dir['path'] = $request->new_path;
                    break;
                }
            }
            Cache::put('orphaned_directories', $orphanedDirs, now()->addDay());

            return back()->with('success', 'Directory renamed successfully');
        }

        return back()->with('error', 'Failed to rename directory');
    }

    public function rescan()
    {
        // Dispatch validation job to run in background
        ValidateBookDirectoriesJob::dispatch();

        return back()->with('success', 'Directory validation started in background. Refresh the page in a few minutes to see results.');
    }
}
