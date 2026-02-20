<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Contracts\DocumentStoreServiceInterface;
use App\Events\NewBookAdded;
use App\Http\Controllers\Controller;
use App\Services\AudibleService;
use App\Services\ExternalCoverService;
use App\Services\GoogleBooksApiService;
use App\Traits\BookImportTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;

class BookImportController extends Controller
{
    use BookImportTrait;

    protected DocumentStoreServiceInterface $documentStoreService;
    protected AudibleService $audibleService;
    protected ExternalCoverService $externalCoverService;

    public function __construct(
        DocumentStoreServiceInterface $documentStoreService,
        GoogleBooksApiService $googleBooksApiService,
        AudibleService $audibleService,
        ExternalCoverService $externalCoverService
    ) {
        $this->documentStoreService = $documentStoreService;
        $this->setGoogleBooksApiService($googleBooksApiService);
        $this->audibleService = $audibleService;
        $this->externalCoverService = $externalCoverService;
    }

    public function import(): View
    {
        return view('admin.books.import_directory');
    }

    /**
     * Show the file/audio import workflow for books.
     */
    public function importFile(): View
    {
        return view('admin.books.import_file');
    }

    /**
     * Process the import of a book from file/audio.
     */
    public function processImport(Request $request): JsonResponse|RedirectResponse
    {
        Log::info('Book import processing started', ['request_data' => $request->except(['cover', 'coverImage'])]);

        try {
            $validated = $request->validate([
                'title' => 'required|string|max:255',
                'author' => 'required|array',
                'author.*' => 'required|string|max:255',
                'genre' => 'required|array',
                'genre.*' => 'required|string|max:255',
                'narrator' => 'nullable|array',
                'narrator.*' => 'nullable|string|max:255',
                'series' => 'nullable|array',
                'series.*.seriesName' => 'nullable|string|max:255',
                'series.*.name' => 'nullable|string|max:255',
                'series.*.number' => 'nullable|max:50',
                'import_path' => 'nullable|string',
                'import_root' => 'nullable|string',
                'import_type' => 'nullable|string',
                'cover_url' => 'nullable|url',
                'description' => 'nullable|string',
                'year' => 'nullable|string|max:4',
                'publisher' => 'nullable|string|max:255',
                'isbn' => 'nullable|string|max:20',
                'language' => 'nullable|string|max:50',
                'pages' => 'nullable|integer',
                'rating' => 'nullable|numeric|min:0|max:5',
            ]);

            $id = (string) Str::uuid();
            $validated['id'] = $id;

            if (empty($validated['author'])) {
                $validated['author'] = ['Unknown'];
            }
            if (empty($validated['genre'])) {
                $validated['genre'] = ['Uncategorized'];
            }

            if (!empty($validated['cover_url'])) {
                try {
                    $coverPath = $this->importCoverImageFromUrl($validated['cover_url']);
                    if ($coverPath) {
                        $validated['cover'] = $coverPath;
                    }
                } catch (\Exception $e) {
                    Log::warning('Failed to import cover image from URL', ['error' => $e->getMessage()]);
                }
                unset($validated['cover_url']);
            }

            if (!empty($validated['series'])) {
                $seriesLinks = [];
                foreach ($validated['series'] as $seriesData) {
                    $seriesName = $seriesData['seriesName'] ?? $seriesData['name'] ?? null;
                    if ($seriesName) {
                        $seriesDoc = $this->documentStoreService->getSeriesByName($seriesName);
                        $seriesId = $seriesDoc['id'] ?? $this->documentStoreService->createSeries($seriesName);
                        $seriesLinks[] = [
                            'id' => $seriesId,
                            'name' => $seriesName,
                            'number' => $seriesData['number'] ?? null
                        ];
                    }
                }
                $validated['series'] = $seriesLinks;
            }

            $importPath = $validated['import_path'] ?? null;
            $importRoot = $validated['import_root'] ?? null;
            $importType = $validated['import_type'] ?? 'dir';
            $directoryPath = null;
            $genrePath = null;

            if ($importPath && $importRoot) {
                $genrePath = $validated['genre'][0] ?? 'Other';
                session(['import_default_genre_path' => $genrePath]);
                $directoryPath = $this->buildDirectoryPath($validated);
                $validated['import_metadata'] = [
                    'path' => $importPath,
                    'root' => $importRoot,
                    'type' => $importType,
                    'imported_at' => now()->toISOString(),
                    'genre_path' => $genrePath,
                    'directory_path' => $directoryPath,
                ];
                unset($validated['import_path'], $validated['import_root'], $validated['import_type']);
            }

            $validated['authors'] = $this->documentStoreService->findOrCreateMany('authors', $validated['author']);
            if (!empty($validated['narrator'])) {
                $validated['narrators'] = $this->documentStoreService->findOrCreateMany('narrators', $validated['narrator']);
            }
            $validated['genres'] = $this->documentStoreService->findOrCreateMany('genres', $validated['genre']);

            $createdId = $this->documentStoreService->createBook($validated);
            if (!empty($createdId)) {
                $id = (string) $createdId;
            }

            if ($importPath && $importRoot && $directoryPath) {
                try {
                    $importFileController = app()->make('App\Http\Controllers\Admin\ImportFileController');
                    $moveRequest = new Request([
                        'path' => $importPath,
                        'root' => $importRoot,
                        'genrePath' => $genrePath,
                        'directoryPath' => $directoryPath,
                        'type' => $importType,
                    ]);
                    $importFileController->moveSelected($moveRequest);
                } catch (\Exception $e) {
                    Log::error('Exception while moving imported files', ['error' => $e->getMessage()]);
                }
            }

            event(new NewBookAdded(['id' => $id, 'title' => $validated['title']]));

            return redirect('/admin/books/' . $id . '/edit')->with('success', 'Book imported successfully.');
        } catch (\Exception $e) {
            Log::error('Book import failed', ['error' => $e->getMessage()]);
            return back()->withErrors(['error' => 'Import failed: ' . $e->getMessage()])->withInput();
        }
    }

    private function buildDirectoryPath(array $bookData): string
    {
        $parts = [];
        $parts[] = $bookData['genre'][0] ?? 'Other';
        if (!empty($bookData['author'])) {
            $parts[] = is_array($bookData['author']) ? $bookData['author'][0] : $bookData['author'];
        }
        if (!empty($bookData['series'])) {
            $series = $bookData['series'][0] ?? null;
            $parts[] = is_array($series) ? ($series['seriesName'] ?? $series['name'] ?? '') : (string) $series;
        }
        if (!empty($bookData['title'])) {
            $parts[] = $bookData['title'];
        }
        return implode('/', array_filter($parts)) ?: 'Unknown';
    }

    /**
     * Extract embedded cover from audio files in the book directory
     */
    public function extractEmbeddedCover(Request $request): JsonResponse
    {
        $directoryPath = $request->input('directory_path');
        if (!$directoryPath) {
            return response()->json(['error' => 'Directory path is required'], 400);
        }

        $bookRoot = rtrim(config('app.book_root', '/media/lyra_data1/audiobooks/books'), '/');
        $fullDirectoryPath = $bookRoot . '/' . ltrim($directoryPath, '/');

        if (!is_dir($fullDirectoryPath)) {
            return response()->json(['error' => 'Directory not found: ' . $directoryPath], 404);
        }

        if (!class_exists('\getID3')) {
            return response()->json(['error' => 'getID3 library not available'], 500);
        }

        $getID3 = new \getID3();
        $getID3->option_tag_id3v1 = false;
        $getID3->option_tag_id3v2 = false;
        $getID3->option_tag_lyrics3 = false;
        $getID3->option_tags_process = true;

        $audioFiles = glob($fullDirectoryPath . '/*.{mp3,m4a,m4b,m4p,mp4,aac,ogg,oga,wav,flac,wma}', GLOB_BRACE);
        if (empty($audioFiles)) {
            return response()->json(['error' => 'No audio files found in directory'], 404);
        }

        $extractedCovers = [];
        foreach ($audioFiles as $audioFile) {
            try {
                $info = $getID3->analyze($audioFile);
                $coverData = null;
                $mimeType = 'image/jpeg';

                if (!empty($info['comments']['picture'][0])) {
                    $coverData = $info['comments']['picture'][0]['data'];
                    $mimeType = $info['comments']['picture'][0]['image_mime'] ?? $mimeType;
                } elseif (!empty($info['id3v2']['APIC'])) {
                    foreach ($info['id3v2']['APIC'] as $pic) {
                        if ($pic['picturetypeid'] === 3 || $pic['picturetypeid'] === 0) {
                            $coverData = $pic['data'];
                            $mimeType = $pic['mime'] ?? $mimeType;
                            break;
                        }
                    }
                }

                if ($coverData) {
                    $extension = $this->getExtensionFromMimeType($mimeType);
                    $tempFile = tempnam(sys_get_temp_dir(), 'embedded_cover_') . '.' . $extension;
                    if (file_put_contents($tempFile, $coverData)) {
                        $extractedCovers[] = [
                            'file' => basename($audioFile),
                            'temp_path' => $tempFile,
                            'mime_type' => $mimeType,
                            'size' => strlen($coverData),
                            'url' => 'data:' . $mimeType . ';base64,' . base64_encode($coverData)
                        ];
                    }
                }
            } catch (\Exception $e) {
                Log::warning('Failed to extract cover from audio file', ['file' => basename($audioFile), 'error' => $e->getMessage()]);
            }
        }

        if (empty($extractedCovers)) {
            return response()->json(['error' => 'No embedded covers found in audio files'], 404);
        }

        return response()->json([
            'success' => true,
            'covers' => $extractedCovers,
            'message' => 'Found ' . count($extractedCovers) . ' embedded cover(s)'
        ]);
    }

    private function getExtensionFromMimeType(string $mimeType): string
    {
        $extensions = [
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
        ];
        return $extensions[$mimeType] ?? 'jpg';
    }
}
