<?php

namespace App\Services\AI;

use App\Models\Author;
use App\Models\Book;
use App\Models\Genre;
use App\Models\Narrator;
use App\Models\Series;
use App\Contracts\DocumentStoreServiceInterface;
use App\Services\AIBookProcessor;
use App\Services\AudioFileAnalyzer;
use App\Services\BookDirectoryMoveService;
use App\Services\BookImportService;
use App\Services\BookTrashService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ToolExecutor
{
    protected string $bookRoot;
    protected array $previewCache = [];
    protected BookTrashService $trashService;
    protected AudioFileAnalyzer $audioAnalyzer;
    protected BookImportService $importService;

    public function __construct()
    {
        $this->bookRoot = rtrim(config('app.book_root', '/media/lyra_data1/audiobooks/books'), '/');
        $this->trashService = app(BookTrashService::class);
        $this->audioAnalyzer = app(AudioFileAnalyzer::class);
        $this->importService = app(BookImportService::class);
    }

    public function execute(string $toolName, array $parameters): array
    {
        try {
            Log::info('Executing tool', [
                'tool' => $toolName,
                'parameters' => $parameters,
            ]);

            $toolName = strip_tags($toolName); // Basic sanitization

            if (!in_array($toolName, array_keys(ToolDefinitions::getAllTools()), true)) {
                Log::warning('Invalid tool requested', ['tool' => $toolName]);
                return [
                    'success' => false,
                    'error' => 'Invalid tool name',
                ];
            }

            $result = match ($toolName) {
                'search_books' => $this->searchBooks($parameters),
                'analyze_series' => $this->analyzeSeries($parameters),
                'get_series_details' => $this->getSeriesDetails($parameters),
                'get_book_details' => $this->getBookDetails($parameters),
                'search_authors' => $this->searchAuthors($parameters),
                'search_genres' => $this->searchGenres($parameters),
                'search_narrators' => $this->searchNarrators($parameters),
                'list_directory' => $this->listDirectory($parameters),
                'scan_book_files' => $this->scanBookFiles($parameters),
                'check_files_exist' => $this->checkFilesExist($parameters),
                'preview_file_move' => $this->previewFileMove($parameters),
                'execute_file_move' => $this->executeFileMove($parameters),
                'pattern_rename_preview' => $this->patternRenamePreview($parameters),
                'bulk_update_preview' => $this->bulkUpdatePreview($parameters),
                'execute_advanced_query' => $this->executeAdvancedQuery($parameters),
                'analyze_data_quality' => $this->analyzeDataQuality($parameters),
                'find_duplicate_books' => $this->findDuplicateBooks($parameters),
                'find_missing_metadata' => $this->findMissingMetadata($parameters),
                'get_recommendations' => $this->getRecommendations($parameters),
                'analyze_collection' => $this->analyzeCollection($parameters),
                'read_audio_metadata' => $this->readAudioMetadata($parameters),
                'create_book' => $this->createBook($parameters),
                'update_book_metadata' => $this->updateBookMetadata($parameters),
                'delete_books' => $this->deleteBooks($parameters),
                'apply_bulk_updates' => $this->applyBulkUpdates($parameters),
                'fetch_external_metadata' => $this->fetchExternalMetadata($parameters),
                'download_cover_image' => $this->downloadCoverImage($parameters),
                'trigger_ai_processing' => $this->triggerAIProcessing($parameters),
                'generate_nfo_files' => $this->generateNFOFiles($parameters),
                'preview_and_execute_bulk_operation' => $this->previewAndExecuteBulkOperation($parameters),
                'extract_and_apply_metadata_from_audio_files' => $this->extractAndApplyMetadataFromAudioFiles($parameters),
                'merge_authors' => $this->mergeAuthors($parameters),
                'merge_series' => $this->mergeSeries($parameters),
                default => ['success' => false, 'error' => "Unknown tool: {$toolName}"],
            };

            Log::info('Tool execution completed', [
                'tool' => $toolName,
                'success' => $result['success'] ?? false,
            ]);

            return $result;
        } catch (\Exception $e) {
            Log::error('Tool execution failed', [
                'tool' => $toolName,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    protected function searchBooks(array $params): array
    {
        $query = Book::query()->with(['authors', 'genres', 'narrators', 'series']);

        if (isset($params['query'])) {
            $searchTerm = $params['query'];
            $query->where(function ($q) use ($searchTerm) {
                $q->where('title', 'like', "%{$searchTerm}%")
                    ->orWhereHas('authors', fn ($q) => $q->where('name', 'like', "%{$searchTerm}%"))
                    ->orWhereHas('series', fn ($q) => $q->where('name', 'like', "%{$searchTerm}%"));
            });
        }

        if (isset($params['title'])) {
            $query->where('title', 'like', "%{$params['title']}%");
        }

        if (isset($params['author'])) {
            $query->whereHas('authors', fn ($q) => $q->where('name', 'like', "%{$params['author']}%"));
        }

        if (isset($params['series'])) {
            $query->whereHas('series', fn ($q) => $q->where('name', 'like', "%{$params['series']}%"));
        }

        if (isset($params['genre'])) {
            $query->whereHas('genres', fn ($q) => $q->where('name', 'like', "%{$params['genre']}%"));
        }

        if (isset($params['narrator'])) {
            $query->whereHas('narrators', fn ($q) => $q->where('name', 'like', "%{$params['narrator']}%"));
        }

        $limit = $params['limit'] ?? 100;
        $books = $query->limit($limit)->get();

        return [
            'success' => true,
            'count' => $books->count(),
            'books' => $books->map(function ($book) {
                return [
                    'id' => $book->id,
                    'title' => $book->title,
                    'authors' => $book->authors->pluck('name')->toArray(),
                    'genres' => $book->genres->pluck('name')->toArray(),
                    'narrators' => $book->narrators->pluck('name')->toArray(),
                    'series' => $book->series->map(fn ($s) => [
                        'name' => $s->name,
                        'number' => $s->pivot ? $s->pivot->getAttribute('series_number') : null,
                    ])->toArray(),
                    'directory_path' => $book->directory_path,
                    'duration' => $book->duration,
                    'release_date' => $book->release_date,
                ];
            })->toArray(),
        ];
    }

    protected function analyzeSeries(array $params): array
    {
        $series = null;

        if (isset($params['series_id'])) {
            $series = Series::find($params['series_id']);
        } elseif (isset($params['series_name'])) {
            $series = Series::where('name', 'like', "%{$params['series_name']}%")->first();
        }

        if (!$series) {
            return [
                'success' => false,
                'error' => 'Series not found',
            ];
        }

        $books = Book::query()->whereHas('series', fn ($q) => $q->where('series_id', $series->id))
            ->with(['authors', 'series'])
            ->get()
            ->map(function ($book) use ($series) {
                $seriesData = $book->series->where('id', $series->id)->first();
                return [
                    'id' => $book->id,
                    'title' => $book->title,
                    'series_number' => $seriesData->pivot->series_number ?? null,
                    'authors' => $book->authors->pluck('name')->toArray(),
                    'directory_path' => $book->directory_path,
                ];
            })
            ->sortBy('series_number')
            ->values();

        $numbers = $books->pluck('series_number')->filter()->sort()->values();
        $gaps = $this->detectGaps($numbers->toArray());
        $duplicates = $this->detectDuplicates($books->toArray());
        $pattern = $this->detectNamingPattern($books->pluck('title')->toArray());

        return [
            'success' => true,
            'series' => [
                'id' => $series->id,
                'name' => $series->name,
                'is_collection' => $series->is_collection,
            ],
            'statistics' => [
                'total_books' => $books->count(),
                'numbered_books' => $numbers->count(),
                'expected_range' => $numbers->isEmpty() ? null : [
                    'min' => $numbers->first(),
                    'max' => $numbers->last(),
                ],
            ],
            'issues' => [
                'gaps' => $gaps,
                'duplicates' => $duplicates,
            ],
            'naming_pattern' => $pattern,
            'books' => $books->toArray(),
        ];
    }

    protected function detectGaps(array $numbers): array
    {
        if (empty($numbers)) {
            return [];
        }

        sort($numbers);
        $gaps = [];
        $min = (int) $numbers[0];
        $max = (int) $numbers[count($numbers) - 1];

        for ($i = $min; $i <= $max; $i++) {
            if (!in_array($i, $numbers)) {
                $gaps[] = $i;
            }
        }

        return $gaps;
    }

    protected function detectDuplicates(array $books): array
    {
        $duplicates = [];
        $numberMap = [];

        foreach ($books as $book) {
            if ($book['series_number'] === null) {
                continue;
            }

            $num = $book['series_number'];
            if (!isset($numberMap[$num])) {
                $numberMap[$num] = [];
            }
            $numberMap[$num][] = $book;
        }

        foreach ($numberMap as $num => $booksWithNum) {
            if (count($booksWithNum) > 1) {
                $duplicates[] = [
                    'series_number' => $num,
                    'books' => $booksWithNum,
                ];
            }
        }

        return $duplicates;
    }

    protected function detectNamingPattern(array $titles): ?string
    {
        if (count($titles) < 3) {
            return null;
        }

        $commonPrefix = $this->findCommonPrefix($titles);
        $commonSuffix = $this->findCommonSuffix($titles);

        $hasNumbering = false;
        foreach ($titles as $title) {
            if (preg_match('/#?\d+/', $title)) {
                $hasNumbering = true;
                break;
            }
        }

        $pattern = '';
        if ($commonPrefix) {
            $pattern .= $commonPrefix . ' ';
        }
        if ($hasNumbering) {
            $pattern .= '#{number}';
        }
        if ($commonSuffix) {
            $pattern .= ' ' . $commonSuffix;
        }

        return $pattern ?: null;
    }

    protected function findCommonPrefix(array $strings): ?string
    {
        if (empty($strings)) {
            return null;
        }

        $prefix = $strings[0];
        foreach ($strings as $string) {
            while (strpos($string, $prefix) !== 0) {
                $prefix = substr($prefix, 0, -1);
                if (empty($prefix)) {
                    return null;
                }
            }
        }

        return trim($prefix);
    }

    protected function findCommonSuffix(array $strings): ?string
    {
        if (empty($strings)) {
            return null;
        }

        $reversed = array_map('strrev', $strings);
        $suffix = $this->findCommonPrefix($reversed);

        return $suffix ? strrev($suffix) : null;
    }

    protected function getSeriesDetails(array $params): array
    {
        $series = null;

        if (isset($params['series_id'])) {
            $series = Series::find($params['series_id']);
        } elseif (isset($params['series_name'])) {
            $series = Series::where('name', 'like', "%{$params['series_name']}%")->first();
        }

        if (!$series) {
            return ['success' => false, 'error' => 'Series not found'];
        }

        $includeBooks = $params['include_books'] ?? true;

        $result = [
            'success' => true,
            'series' => [
                'id' => $series->id,
                'name' => $series->name,
                'is_collection' => $series->is_collection,
                'book_count' => $series->books()->count(),
            ],
        ];

        if ($includeBooks) {
            $books = $series->books()->with(['authors', 'genres', 'narrators'])->get();
            $result['books'] = $books->map(function ($book) use ($series) {
                return [
                    'id' => $book->id,
                    'title' => $book->title,
                    'series_number' => $book->pivot->series_number,
                    'authors' => $book->authors->pluck('name')->toArray(),
                    'genres' => $book->genres->pluck('name')->toArray(),
                    'narrators' => $book->narrators->pluck('name')->toArray(),
                    'directory_path' => $book->directory_path,
                    'duration' => $book->duration,
                ];
            })->sortBy('series_number')->values()->toArray();
        }

        return $result;
    }

    protected function getBookDetails(array $params): array
    {
        $book = null;

        if (isset($params['book_id'])) {
            $book = Book::with(['authors', 'genres', 'narrators', 'series', 'publisher'])->find($params['book_id']);
        } elseif (isset($params['title'])) {
            $book = Book::with(['authors', 'genres', 'narrators', 'series', 'publisher'])
                ->where('title', 'like', "%{$params['title']}%")
                ->first();
        }

        if (!$book) {
            return ['success' => false, 'error' => 'Book not found'];
        }

        return [
            'success' => true,
            'book' => [
                'id' => $book->id,
                'title' => $book->title,
                'description' => $book->description,
                'authors' => $book->authors->map(fn ($a) => ['id' => $a->id, 'name' => $a->name])->toArray(),
                'genres' => $book->genres->map(fn ($g) => ['id' => $g->id, 'name' => $g->name])->toArray(),
                'narrators' => $book->narrators->map(fn ($n) => ['id' => $n->id, 'name' => $n->name])->toArray(),
                'series' => $book->series->map(fn ($s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'number' => $s->pivot->series_number,
                ])->toArray(),
                'publisher' => $book->publisher ? $book->publisher->name : null,
                'directory_path' => $book->directory_path,
                'release_date' => $book->release_date,
                'language' => $book->language,
                'isbn' => $book->isbn,
                'duration' => $book->duration,
                'audio_file_count' => $book->audio_file_count,
                'ai_processed' => $book->ai_processed,
                'ai_confidence' => $book->ai_confidence,
                'needs_review' => $book->needs_review,
            ],
        ];
    }

    protected function searchAuthors(array $params): array
    {
        $query = Author::query();

        if (isset($params['name'])) {
            $query->where('name', 'like', "%{$params['name']}%");
        }

        $limit = $params['limit'] ?? 50;
        $includeBooks = $params['include_books'] ?? false;

        $authors = $query->withCount('books')->limit($limit)->get();

        $result = [
            'success' => true,
            'count' => $authors->count(),
            'authors' => $authors->map(function ($author) use ($includeBooks) {
                $data = [
                    'id' => $author->id,
                    'name' => $author->name,
                    'book_count' => $author->books_count,
                ];

                if ($includeBooks) {
                    $data['books'] = $author->books()->select('id', 'title')->get()->toArray();
                }

                return $data;
            })->toArray(),
        ];

        return $result;
    }

    protected function searchGenres(array $params): array
    {
        $query = Genre::query();

        if (isset($params['name'])) {
            $query->where('name', 'like', "%{$params['name']}%");
        }

        $includeBooks = $params['include_books'] ?? false;

        $genres = $query->withCount('books')->get();

        return [
            'success' => true,
            'count' => $genres->count(),
            'genres' => $genres->map(function ($genre) use ($includeBooks) {
                $data = [
                    'id' => $genre->id,
                    'name' => $genre->name,
                    'book_count' => $genre->books_count,
                ];

                if ($includeBooks) {
                    $data['books'] = $genre->books()->select('id', 'title')->get()->toArray();
                }

                return $data;
            })->toArray(),
        ];
    }

    protected function searchNarrators(array $params): array
    {
        $query = Narrator::query();

        if (isset($params['name'])) {
            $query->where('name', 'like', "%{$params['name']}%");
        }

        $limit = $params['limit'] ?? 50;
        $includeBooks = $params['include_books'] ?? false;

        $narrators = $query->withCount('books')->limit($limit)->get();

        return [
            'success' => true,
            'count' => $narrators->count(),
            'narrators' => $narrators->map(function ($narrator) use ($includeBooks) {
                $data = [
                    'id' => $narrator->id,
                    'name' => $narrator->name,
                    'book_count' => $narrator->books_count,
                ];

                if ($includeBooks) {
                    $data['books'] = $narrator->books()->select('id', 'title')->get()->toArray();
                }

                return $data;
            })->toArray(),
        ];
    }

    protected function listDirectory(array $params): array
    {
        $path = $params['path'];
        $recursive = $params['recursive'] ?? false;
        $fileTypes = $params['file_types'] ?? null;

        if (!str_starts_with($path, '/')) {
            $path = $this->bookRoot . '/' . ltrim($path, '/');
        }

        if (!File::exists($path)) {
            return ['success' => false, 'error' => 'Directory not found'];
        }

        if (!File::isDirectory($path)) {
            return ['success' => false, 'error' => 'Path is not a directory'];
        }

        $files = $recursive ? File::allFiles($path) : File::files($path);
        $directories = $recursive ? [] : File::directories($path);

        $fileList = collect($files)->map(function ($file) use ($fileTypes) {
            $extension = strtolower($file->getExtension());

            if ($fileTypes && !in_array($extension, $fileTypes)) {
                return null;
            }

            return [
                'name' => $file->getFilename(),
                'path' => $file->getPathname(),
                'size' => $file->getSize(),
                'extension' => $extension,
                'modified' => date('Y-m-d H:i:s', $file->getMTime()),
            ];
        })->filter()->values();

        $dirList = collect($directories)->map(function ($dir) {
            return [
                'name' => basename($dir),
                'path' => $dir,
                'type' => 'directory',
            ];
        });

        return [
            'success' => true,
            'path' => $path,
            'directories' => $dirList->toArray(),
            'files' => $fileList->toArray(),
            'total_files' => $fileList->count(),
            'total_directories' => $dirList->count(),
        ];
    }

    protected function scanBookFiles(array $params): array
    {
        $book = null;
        $directoryPath = null;

        if (isset($params['book_id'])) {
            $book = Book::find($params['book_id']);
            if (!$book) {
                return ['success' => false, 'error' => 'Book not found'];
            }
            $directoryPath = $this->bookRoot . '/' . ltrim($book->directory_path, '/');
        } elseif (isset($params['directory_path'])) {
            $directoryPath = $params['directory_path'];
            if (!str_starts_with($directoryPath, '/')) {
                $directoryPath = $this->bookRoot . '/' . ltrim($directoryPath, '/');
            }
        }

        if (!$directoryPath || !File::exists($directoryPath)) {
            return ['success' => false, 'error' => 'Directory not found'];
        }

        $audioExtensions = ['m4b', 'mp3', 'mp4', 'ogg', 'flac', 'wav', 'm4a'];
        $files = File::allFiles($directoryPath);

        $audioFiles = collect($files)->filter(function ($file) use ($audioExtensions) {
            return in_array(strtolower($file->getExtension()), $audioExtensions);
        })->map(function ($file) {
            return [
                'name' => $file->getFilename(),
                'path' => $file->getPathname(),
                'size' => $file->getSize(),
                'size_mb' => round($file->getSize() / 1024 / 1024, 2),
                'extension' => $file->getExtension(),
                'modified' => date('Y-m-d H:i:s', $file->getMTime()),
            ];
        })->values();

        return [
            'success' => true,
            'directory' => $directoryPath,
            'book_id' => $book?->id,
            'audio_files' => $audioFiles->toArray(),
            'total_files' => $audioFiles->count(),
            'total_size_mb' => $audioFiles->sum('size_mb'),
        ];
    }

    protected function checkFilesExist(array $params): array
    {
        $results = [];

        if (isset($params['book_ids'])) {
            $books = Book::query()->whereIn('id', $params['book_ids'])->get();
            foreach ($books as $book) {
                $path = $this->bookRoot . '/' . ltrim($book->directory_path, '/');
                $results[] = [
                    'book_id' => $book->id,
                    'title' => $book->title,
                    'path' => $book->directory_path,
                    'exists' => File::exists($path),
                    'is_directory' => File::isDirectory($path),
                ];
            }
        }

        if (isset($params['paths'])) {
            foreach ($params['paths'] as $path) {
                if (!str_starts_with($path, '/')) {
                    $path = $this->bookRoot . '/' . ltrim($path, '/');
                }
                $results[] = [
                    'path' => $path,
                    'exists' => File::exists($path),
                    'is_directory' => File::isDirectory($path),
                    'is_file' => File::isFile($path),
                ];
            }
        }

        return [
            'success' => true,
            'results' => $results,
        ];
    }

    protected function previewFileMove(array $params): array
    {
        $moves = $params['moves'];
        $previewId = Str::uuid()->toString();
        $preview = [];

        foreach ($moves as $move) {
            $bookId = $move['book_id'] ?? null;
            $fromPath = $move['from_path'];
            $toPath = $move['to_path'];

            if (!str_starts_with($fromPath, '/')) {
                $fromPath = $this->bookRoot . '/' . ltrim($fromPath, '/');
            }
            if (!str_starts_with($toPath, '/')) {
                $toPath = $this->bookRoot . '/' . ltrim($toPath, '/');
            }

            $issues = [];
            if (!File::exists($fromPath)) {
                $issues[] = 'Source does not exist';
            }
            if (File::exists($toPath)) {
                $issues[] = 'Destination already exists';
            }

            $preview[] = [
                'book_id' => $bookId,
                'from' => $fromPath,
                'to' => $toPath,
                'source_exists' => File::exists($fromPath),
                'destination_exists' => File::exists($toPath),
                'issues' => $issues,
                'can_proceed' => empty($issues),
            ];
        }

        $this->previewCache[$previewId] = [
            'moves' => $preview,
            'created_at' => now(),
        ];

        return [
            'success' => true,
            'preview_id' => $previewId,
            'moves' => $preview,
            'total' => count($preview),
            'can_proceed' => collect($preview)->every(fn ($m) => $m['can_proceed']),
        ];
    }

    protected function executeFileMove(array $params): array
    {
        $confirmedMoves = $params['confirmed_moves'];
        $previewId = $params['preview_id'] ?? null;

        $preview = $previewId && isset($this->previewCache[$previewId]) ? $this->previewCache[$previewId]['moves'] : null;

        if (!$preview) {
            return ['success' => false, 'error' => 'Invalid or expired preview ID. Please run preview_file_move first.'];
        }

        $results = [];
        $successCount = 0;
        $failureCount = 0;

        foreach ($preview as $move) {
            if (!in_array($move['book_id'], $confirmedMoves)) {
                continue;
            }

            try {
                $targetDir = dirname($move['to']);
                if (!File::exists($targetDir)) {
                    File::makeDirectory($targetDir, 0755, true);
                }

                $success = File::move($move['from'], $move['to']);

                if ($success && $move['book_id']) {
                    $book = Book::find($move['book_id']);
                    if ($book) {
                        $relativePath = str_replace($this->bookRoot . '/', '', $move['to']);
                        $book->directory_path = dirname($relativePath);
                        $book->save();
                    }
                }

                $results[] = [
                    'book_id' => $move['book_id'],
                    'from' => $move['from'],
                    'to' => $move['to'],
                    'success' => $success,
                ];

                if ($success) {
                    $successCount++;
                } else {
                    $failureCount++;
                }
            } catch (\Exception $e) {
                $results[] = [
                    'book_id' => $move['book_id'],
                    'from' => $move['from'],
                    'to' => $move['to'],
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
                $failureCount++;
            }
        }

        return [
            'success' => true,
            'results' => $results,
            'summary' => [
                'total' => count($results),
                'successful' => $successCount,
                'failed' => $failureCount,
            ],
        ];
    }

    protected function patternRenamePreview(array $params): array
    {
        $template = $params['template'];
        $applyTo = $params['apply_to'] ?? 'title';
        $books = collect();

        if (isset($params['series_id'])) {
            $books = Book::query()->whereHas('series', fn ($q) => $q->where('series_id', $params['series_id']))
                ->with(['authors', 'series'])
                ->get();
        } elseif (isset($params['book_ids'])) {
            $books = Book::query()->with(['authors', 'series'])->whereIn('id', $params['book_ids'])->get();
        }

        if ($books->isEmpty()) {
            return ['success' => false, 'error' => 'No books found'];
        }

        $preview = $books->map(function ($book) use ($template, $applyTo) {
            $series = $book->series->first();
            $variables = [
                '{series}' => $series?->name ?? '',
                '{number}' => $series?->pivot->series_number ?? '',
                '{title}' => $book->title,
                '{author}' => $book->authors->first()?->name ?? '',
                '{narrator}' => $book->narrators->first()?->name ?? '',
            ];

            $newValue = str_replace(array_keys($variables), array_values($variables), $template);

            return [
                'book_id' => $book->id,
                'current_title' => $book->title,
                'new_title' => $applyTo !== 'directory' ? $newValue : null,
                'current_directory' => $book->directory_path,
                'new_directory' => $applyTo !== 'title' ? $newValue : null,
                'apply_to' => $applyTo,
            ];
        });

        return [
            'success' => true,
            'preview' => $preview->toArray(),
            'total' => $preview->count(),
        ];
    }

    protected function bulkUpdatePreview(array $params): array
    {
        $bookIds = $params['book_ids'];
        $updates = $params['updates'];

        $books = Book::query()->with(['authors', 'genres', 'narrators', 'series'])->whereIn('id', $bookIds)->get();

        $preview = $books->map(function ($book) use ($updates) {
            return [
                'book_id' => $book->id,
                'title' => $book->title,
                'current' => [
                    'genres' => $book->genres->pluck('name')->toArray(),
                    'authors' => $book->authors->pluck('name')->toArray(),
                ],
                'updates' => $updates,
            ];
        });

        return [
            'success' => true,
            'preview' => $preview->toArray(),
            'total' => $preview->count(),
        ];
    }

    protected function executeAdvancedQuery(array $params): array
    {
        $description = $params['description'];
        $queryType = $params['query_type'];
        $queryParams = $params['parameters'] ?? [];

        return match ($queryType) {
            'count' => $this->executeCountQuery($queryParams),
            'aggregate' => $this->executeAggregateQuery($queryParams),
            'list' => $this->executeListQuery($queryParams),
            'statistics' => $this->executeStatisticsQuery($queryParams),
            default => ['success' => false, 'error' => 'Unknown query type'],
        };
    }

    protected function executeCountQuery(array $params): array
    {
        $entity = $params['entity'] ?? 'books';
        $filters = $params['filters'] ?? [];

        $query = match ($entity) {
            'books' => Book::query(),
            'authors' => Author::query(),
            'genres' => Genre::query(),
            'series' => Series::query(),
            default => null,
        };

        if (!$query) {
            return ['success' => false, 'error' => 'Invalid entity type'];
        }

        foreach ($filters as $field => $value) {
            $query->where($field, $value);
        }

        return [
            'success' => true,
            'count' => $query->count(),
        ];
    }

    protected function executeAggregateQuery(array $params): array
    {
        return [
            'success' => true,
            'message' => 'Aggregate queries not yet implemented',
        ];
    }

    protected function executeListQuery(array $params): array
    {
        return [
            'success' => true,
            'message' => 'List queries not yet implemented',
        ];
    }

    protected function executeStatisticsQuery(array $params): array
    {
        $stats = [
            'total_books' => Book::count(),
            'total_authors' => Author::count(),
            'total_genres' => Genre::count(),
            'total_series' => Series::count(),
            'total_narrators' => Narrator::count(),
            'books_with_ai_processing' => Book::query()->where('ai_processed', true)->count(),
            'books_needing_review' => Book::query()->where('needs_review', true)->count(),
        ];

        return [
            'success' => true,
            'statistics' => $stats,
        ];
    }

    protected function analyzeDataQuality(array $params): array
    {
        $checkTypes = $params['check_types'] ?? ['all'];
        $limit = $params['limit'] ?? 50;
        $issues = [];

        if (in_array('all', $checkTypes) || in_array('missing_metadata', $checkTypes)) {
            $issues['missing_metadata'] = [
                'no_author' => Book::doesntHave('authors')->limit($limit)->get(['id', 'title'])->toArray(),
                'no_genre' => Book::doesntHave('genres')->limit($limit)->get(['id', 'title'])->toArray(),
                'no_description' => Book::query()->whereNull('description')->orWhere('description', '')->limit($limit)->get(['id', 'title'])->toArray(),
            ];
        }

        if (in_array('all', $checkTypes) || in_array('orphaned_records', $checkTypes)) {
            $issues['orphaned_records'] = [
                'authors_without_books' => Author::doesntHave('books')->limit($limit)->get(['id', 'name'])->toArray(),
                'genres_without_books' => Genre::doesntHave('books')->limit($limit)->get(['id', 'name'])->toArray(),
                'series_without_books' => Series::doesntHave('books')->limit($limit)->get(['id', 'name'])->toArray(),
            ];
        }

        if (in_array('all', $checkTypes) || in_array('filesystem_mismatches', $checkTypes)) {
            $books = Book::limit($limit)->get();
            $mismatches = [];
            foreach ($books as $book) {
                $path = $this->bookRoot . '/' . ltrim($book->directory_path, '/');
                if (!File::exists($path)) {
                    $mismatches[] = [
                        'id' => $book->id,
                        'title' => $book->title,
                        'path' => $book->directory_path,
                        'issue' => 'path_not_found',
                    ];
                }
            }
            $issues['filesystem_mismatches'] = $mismatches;
        }

        return [
            'success' => true,
            'issues' => $issues,
            'summary' => [
                'total_issues' => array_sum(array_map(fn ($cat) => is_array($cat) ? count($cat) : 0, $issues)),
            ],
        ];
    }

    protected function findDuplicateBooks(array $params): array
    {
        $method = $params['method'] ?? 'all';
        $threshold = $params['threshold'] ?? 0.85;
        $includeSeriesBooks = $params['include_series_books'] ?? false;
        $duplicates = [];

        if ($method === 'exact_title' || $method === 'all') {
            $titleGroups = Book::select('title', DB::raw('GROUP_CONCAT(id) as book_ids'), DB::raw('COUNT(*) as count'))
                ->groupBy('title')
                ->having('count', '>', 1)
                ->get();

            foreach ($titleGroups as $group) {
                $bookIds = explode(',', $group->book_ids);
                $books = Book::query()->with(['authors', 'series'])->whereIn('id', $bookIds)->get();

                $duplicates[] = [
                    'method' => 'exact_title',
                    'title' => $group->title,
                    'count' => $group->count,
                    'books' => $books->map(fn ($b) => [
                        'id' => $b->id,
                        'title' => $b->title,
                        'authors' => $b->authors->pluck('name')->toArray(),
                        'series' => $b->series->pluck('name')->toArray(),
                    ])->toArray(),
                ];
            }
        }

        if ($method === 'isbn' || $method === 'all') {
            $isbnGroups = Book::select('isbn', DB::raw('GROUP_CONCAT(id) as book_ids'), DB::raw('COUNT(*) as count'))
                ->whereNotNull('isbn')
                ->where('isbn', '!=', '')
                ->groupBy('isbn')
                ->having('count', '>', 1)
                ->get();

            foreach ($isbnGroups as $group) {
                $bookIds = explode(',', $group->book_ids);
                $books = Book::query()->with(['authors'])->whereIn('id', $bookIds)->get();

                $duplicates[] = [
                    'method' => 'isbn',
                    'isbn' => $group->isbn,
                    'count' => $group->count,
                    'books' => $books->map(fn ($b) => [
                        'id' => $b->id,
                        'title' => $b->title,
                        'authors' => $b->authors->pluck('name')->toArray(),
                    ])->toArray(),
                ];
            }
        }

        return [
            'success' => true,
            'duplicates' => $duplicates,
            'total_duplicate_groups' => count($duplicates),
        ];
    }

    protected function findMissingMetadata(array $params): array
    {
        $metadataTypes = $params['metadata_types'] ?? ['all'];
        $limit = $params['limit'] ?? 50;
        $missing = [];

        if (in_array('all', $metadataTypes) || in_array('author', $metadataTypes)) {
            $missing['no_author'] = Book::doesntHave('authors')->limit($limit)->get(['id', 'title', 'directory_path'])->toArray();
        }

        if (in_array('all', $metadataTypes) || in_array('genre', $metadataTypes)) {
            $missing['no_genre'] = Book::doesntHave('genres')->limit($limit)->get(['id', 'title', 'directory_path'])->toArray();
        }

        if (in_array('all', $metadataTypes) || in_array('series', $metadataTypes)) {
            $missing['no_series'] = Book::doesntHave('series')->limit($limit)->get(['id', 'title', 'directory_path'])->toArray();
        }

        if (in_array('all', $metadataTypes) || in_array('narrator', $metadataTypes)) {
            $missing['no_narrator'] = Book::doesntHave('narrators')->limit($limit)->get(['id', 'title', 'directory_path'])->toArray();
        }

        if (in_array('all', $metadataTypes) || in_array('cover', $metadataTypes)) {
            $missing['no_cover'] = Book::query()->whereNull('cover_image')->orWhere('cover_image', '')->limit($limit)->get(['id', 'title', 'directory_path'])->toArray();
        }

        if (in_array('all', $metadataTypes) || in_array('description', $metadataTypes)) {
            $missing['no_description'] = Book::query()->whereNull('description')->orWhere('description', '')->limit($limit)->get(['id', 'title', 'directory_path'])->toArray();
        }

        if (in_array('all', $metadataTypes) || in_array('isbn', $metadataTypes)) {
            $missing['no_isbn'] = Book::query()->whereNull('isbn')->orWhere('isbn', '')->limit($limit)->get(['id', 'title', 'directory_path'])->toArray();
        }

        return [
            'success' => true,
            'missing' => $missing,
            'summary' => array_map('count', $missing),
        ];
    }

    protected function getRecommendations(array $params): array
    {
        $basedOn = $params['based_on'];
        $limit = $params['limit'] ?? 10;
        $recommendations = [];

        switch ($basedOn) {
            case 'book':
                $bookId = $params['book_id'];
                $book = Book::with(['authors', 'genres', 'series'])->find($bookId);
                if (!$book) {
                    return ['success' => false, 'error' => 'Book not found'];
                }

                $recommendations = Book::query()->where('id', '!=', $bookId)
                    ->where(function ($q) use ($book) {
                        $q->whereHas('authors', fn ($q) => $q->whereIn('id', $book->authors->pluck('id')))
                            ->orWhereHas('genres', fn ($q) => $q->whereIn('id', $book->genres->pluck('id')))
                            ->orWhereHas('series', fn ($q) => $q->whereIn('id', $book->series->pluck('id')));
                    })
                    ->with(['authors', 'genres'])
                    ->limit($limit)
                    ->get();
                break;

            case 'author':
                $authorName = $params['author_name'];
                $author = Author::where('name', 'like', "%{$authorName}%")->first();
                if (!$author) {
                    return ['success' => false, 'error' => 'Author not found'];
                }

                $recommendations = $author->books()->with(['authors', 'genres'])->limit($limit)->get();
                break;

            case 'genre':
                $genreName = $params['genre_name'];
                $genre = Genre::where('name', 'like', "%{$genreName}%")->first();
                if (!$genre) {
                    return ['success' => false, 'error' => 'Genre not found'];
                }

                $recommendations = $genre->books()->with(['authors', 'genres'])->limit($limit)->get();
                break;

            case 'series':
                $seriesName = $params['series_name'];
                $series = Series::where('name', 'like', "%{$seriesName}%")->first();
                if (!$series) {
                    return ['success' => false, 'error' => 'Series not found'];
                }

                $recommendations = $series->books()->with(['authors', 'genres'])->limit($limit)->get();
                break;
        }

        return [
            'success' => true,
            'based_on' => $basedOn,
            'recommendations' => $recommendations->map(fn ($b) => [
                'id' => $b->id,
                'title' => $b->title,
                'authors' => $b->authors->pluck('name')->toArray(),
                'genres' => $b->genres->pluck('name')->toArray(),
            ])->toArray(),
        ];
    }

    protected function analyzeCollection(array $params): array
    {
        $analysisTypes = $params['analysis_types'] ?? ['overview'];
        $includeChartsData = $params['include_charts_data'] ?? false;
        $analysis = [];

        if (in_array('all', $analysisTypes) || in_array('overview', $analysisTypes)) {
            $analysis['overview'] = [
                'total_books' => Book::count(),
                'total_authors' => Author::count(),
                'total_genres' => Genre::count(),
                'total_series' => Series::count(),
                'total_narrators' => Narrator::count(),
                'total_duration_hours' => round(Book::sum('duration') / 3600, 2),
                'books_with_series' => Book::has('series')->count(),
                'books_without_series' => Book::doesntHave('series')->count(),
                'ai_processed_books' => Book::query()->where('ai_processed', true)->count(),
            ];
        }

        if (in_array('all', $analysisTypes) || in_array('genres', $analysisTypes)) {
            $genreStats = Genre::withCount('books')
                ->orderBy('books_count', 'desc')
                ->get()
                ->map(fn ($g) => [
                    'name' => $g->name,
                    'count' => $g->books_count,
                ]);

            $analysis['genres'] = [
                'distribution' => $genreStats->toArray(),
                'top_genre' => $genreStats->first(),
            ];
        }

        if (in_array('all', $analysisTypes) || in_array('authors', $analysisTypes)) {
            $authorStats = Author::withCount('books')
                ->orderBy('books_count', 'desc')
                ->limit(20)
                ->get()
                ->map(fn ($a) => [
                    'name' => $a->name,
                    'count' => $a->books_count,
                ]);

            $analysis['authors'] = [
                'top_authors' => $authorStats->toArray(),
                'total_authors' => Author::count(),
            ];
        }

        if (in_array('all', $analysisTypes) || in_array('series', $analysisTypes)) {
            $seriesStats = Series::withCount('books')
                ->orderBy('books_count', 'desc')
                ->limit(20)
                ->get()
                ->map(fn ($s) => [
                    'name' => $s->name,
                    'count' => $s->books_count,
                ]);

            $analysis['series'] = [
                'top_series' => $seriesStats->toArray(),
                'total_series' => Series::count(),
                'series_with_10plus_books' => Series::withCount('books')->having('books_count', '>=', 10)->count(),
            ];
        }

        if (in_array('all', $analysisTypes) || in_array('quality', $analysisTypes)) {
            $analysis['quality'] = [
                'books_missing_author' => Book::doesntHave('authors')->count(),
                'books_missing_genre' => Book::doesntHave('genres')->count(),
                'books_missing_description' => Book::query()->whereNull('description')->orWhere('description', '')->count(),
                'books_needing_review' => Book::query()->where('needs_review', true)->count(),
                'ai_unprocessed' => Book::query()->where('ai_processed', false)->count(),
            ];
        }

        return [
            'success' => true,
            'analysis' => $analysis,
        ];
    }

    protected function readAudioMetadata(array $params): array
    {
        try {
            $bookId = $params['book_id'] ?? null;
            $filePath = $params['file_path'] ?? null;
            $includeChapters = $params['include_chapters'] ?? false;

            if ($bookId) {
                $book = Book::find($bookId);
                if (!$book) {
                    return ['success' => false, 'error' => 'Book not found'];
                }
                $basePath = $this->bookRoot . '/' . ltrim($book->directory_path, '/');
            } elseif ($filePath) {
                $basePath = str_starts_with($filePath, '/') ? $filePath : $this->bookRoot . '/' . ltrim($filePath, '/');
            } else {
                return ['success' => false, 'error' => 'Either book_id or file_path required'];
            }

            if (!File::exists($basePath)) {
                return ['success' => false, 'error' => 'Path does not exist'];
            }

            $audioFiles = [];
            if (File::isDirectory($basePath)) {
                $files = File::allFiles($basePath);
                foreach ($files as $file) {
                    if (preg_match('/\.(m4b|mp3|mp4|ogg|flac|wav|m4a)$/i', $file->getFilename())) {
                        $audioFiles[] = $file->getPathname();
                    }
                }
            } else {
                $audioFiles[] = $basePath;
            }

            if (empty($audioFiles)) {
                return ['success' => false, 'error' => 'No audio files found'];
            }

            $metadata = [];
            foreach (array_slice($audioFiles, 0, 5) as $audioFile) {
                $fileMetadata = [
                    'file' => basename($audioFile),
                    'path' => $audioFile,
                    'size' => File::size($audioFile),
                    'tags' => [],
                ];

                if (function_exists('shell_exec')) {
                    $ffprobeOutput = shell_exec('ffprobe -v quiet -print_format json -show_format -show_chapters ' . escapeshellarg($audioFile) . ' 2>&1');
                    if ($ffprobeOutput) {
                        $ffprobeData = json_decode($ffprobeOutput, true);
                        if ($ffprobeData) {
                            if (isset($ffprobeData['format'])) {
                                $fileMetadata['duration'] = $ffprobeData['format']['duration'] ?? null;
                                $fileMetadata['bitrate'] = $ffprobeData['format']['bit_rate'] ?? null;
                                $fileMetadata['format'] = $ffprobeData['format']['format_name'] ?? null;
                                $fileMetadata['tags'] = $ffprobeData['format']['tags'] ?? [];
                            }
                            if ($includeChapters && isset($ffprobeData['chapters'])) {
                                $fileMetadata['chapters'] = array_map(fn ($ch) => [
                                    'id' => $ch['id'] ?? null,
                                    'start' => $ch['start_time'] ?? null,
                                    'end' => $ch['end_time'] ?? null,
                                    'title' => $ch['tags']['title'] ?? null,
                                ], $ffprobeData['chapters']);
                            }
                        }
                    }
                }

                $metadata[] = $fileMetadata;
            }

            return [
                'success' => true,
                'metadata' => $metadata,
                'total_files' => count($audioFiles),
                'files_analyzed' => count($metadata),
            ];
        } catch (\Exception $e) {
            Log::error('read_audio_metadata failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    protected function createBook(array $params): array
    {
        try {
            $directoryPath = $params['directory_path'];
            $autoDiscoverMetadata = $params['auto_discover_metadata'] ?? true;
            $confirmed = $params['confirmed'] ?? false;

            if (!$confirmed) {
                return [
                    'success' => false,
                    'error' => 'Confirmation required. Set confirmed=true to create book',
                    'requires_confirmation' => true,
                ];
            }

            $fullPath = str_starts_with($directoryPath, '/') ? $directoryPath : $this->bookRoot . '/' . ltrim($directoryPath, '/');

            if (!File::exists($fullPath) || !File::isDirectory($fullPath)) {
                return ['success' => false, 'error' => 'Directory does not exist'];
            }

            $audioFiles = collect(File::allFiles($fullPath))
                ->filter(fn ($file) => preg_match('/\.(m4b|mp3|mp4|ogg|flac|wav|m4a)$/i', $file->getFilename()))
                ->values();

            if ($audioFiles->isEmpty()) {
                return ['success' => false, 'error' => 'No audio files found in directory'];
            }

            $pathParts = explode('/', trim(str_replace($this->bookRoot, '', $fullPath), '/'));
            $bookData = [
                'directory_path' => str_replace($this->bookRoot . '/', '', $fullPath),
                'audio_file_count' => $audioFiles->count(),
            ];

            if ($autoDiscoverMetadata && count($pathParts) >= 3) {
                $bookData['title'] = $pathParts[count($pathParts) - 1] ?? 'Unknown';
                $authorName = $pathParts[count($pathParts) - 2] ?? null;
                $genreName = $pathParts[0] ?? null;

                if ($authorName) {
                    $author = Author::firstOrCreate(['name' => $authorName]);
                    $bookData['author_id'] = $author->id;
                }

                if ($genreName) {
                    $genre = Genre::firstOrCreate(['name' => $genreName]);
                    $bookData['genre_id'] = $genre->id;
                }
            }

            if (isset($params['title'])) {
                $bookData['title'] = $params['title'];
            }
            if (isset($params['description'])) {
                $bookData['description'] = $params['description'];
            }

            $book = new Book($bookData);
            $book->save();

            if (isset($bookData['author_id'])) {
                $book->authors()->attach($bookData['author_id']);
            }
            if (isset($bookData['genre_id'])) {
                $book->genres()->attach($bookData['genre_id']);
            }

            Log::info('Book created', ['book_id' => $book->id, 'directory' => $directoryPath]);

            return [
                'success' => true,
                'book' => [
                    'id' => $book->id,
                    'title' => $book->title,
                    'directory_path' => $book->directory_path,
                    'audio_file_count' => $book->audio_file_count,
                ],
            ];
        } catch (\Exception $e) {
            Log::error('create_book failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    protected function updateBookMetadata(array $params): array
    {
        try {
            $bookId = $params['book_id'];
            $updates = $params['updates'];
            $previewOnly = $params['preview_only'] ?? true;
            $createBackup = $params['create_backup'] ?? true;

            $book = Book::with(['authors', 'genres', 'series', 'narrators'])->find($bookId);
            if (!$book) {
                return ['success' => false, 'error' => 'Book not found'];
            }

            $currentMetadata = [
                'title' => $book->title,
                'description' => $book->description,
                'authors' => $book->authors->pluck('name')->toArray(),
                'genres' => $book->genres->pluck('name')->toArray(),
                'narrators' => $book->narrators->pluck('name')->toArray(),
                'series' => $book->series->pluck('name')->toArray(),
                'language' => $book->language,
                'isbn' => $book->isbn,
                'release_date' => $book->release_date,
            ];

            $proposedChanges = [];
            foreach ($updates as $field => $value) {
                if (isset($currentMetadata[$field]) && $currentMetadata[$field] !== $value) {
                    $proposedChanges[$field] = [
                        'old' => $currentMetadata[$field],
                        'new' => $value,
                    ];
                }
            }

            if ($previewOnly) {
                return [
                    'success' => true,
                    'preview' => true,
                    'book_id' => $bookId,
                    'current_metadata' => $currentMetadata,
                    'proposed_changes' => $proposedChanges,
                    'message' => 'This is a preview. Set preview_only=false to apply changes.',
                ];
            }

            if ($createBackup) {
                $backupResult = $this->trashService->createBackup((string)$bookId, 'before_metadata_update');
                if (!$backupResult['success']) {
                    Log::warning('Failed to create backup before update', ['book_id' => $bookId]);
                }
            }

            DB::transaction(function () use ($book, $updates) {
                foreach ($updates as $field => $value) {
                    switch ($field) {
                        case 'title':
                        case 'description':
                        case 'language':
                        case 'isbn':
                        case 'release_date':
                            $book->$field = $value;
                            break;

                        case 'authors':
                            $authorIds = collect($value)->map(function ($authorName) {
                                return Author::firstOrCreate(['name' => $authorName])->id;
                            });
                            $book->authors()->sync($authorIds);
                            break;

                        case 'genres':
                            $genreIds = collect($value)->map(function ($genreName) {
                                return Genre::firstOrCreate(['name' => $genreName])->id;
                            });
                            $book->genres()->sync($genreIds);
                            break;

                        case 'narrators':
                            $narratorIds = collect($value)->map(function ($narratorName) {
                                return Narrator::firstOrCreate(['name' => $narratorName])->id;
                            });
                            $book->narrators()->sync($narratorIds);
                            break;
                    }
                }
                $book->save();
            });

            $book->refresh();
            Log::info('Book metadata updated', ['book_id' => $bookId, 'changes' => $proposedChanges]);

            return [
                'success' => true,
                'book_id' => $bookId,
                'changes_applied' => $proposedChanges,
            ];
        } catch (\Exception $e) {
            Log::error('update_book_metadata failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    protected function deleteBooks(array $params): array
    {
        try {
            $bookIds = $params['book_ids'];
            $deleteFiles = $params['delete_files'] ?? false;
            $reason = $params['reason'];
            $confirmed = $params['confirmed'] ?? false;

            if (!$confirmed) {
                $books = Book::query()->whereIn('id', $bookIds)->get(['id', 'title']);
                return [
                    'success' => false,
                    'error' => 'Confirmation required. Set confirmed=true to delete books',
                    'requires_confirmation' => true,
                    'books_to_delete' => $books->map(fn ($b) => [
                        'id' => $b->id,
                        'title' => $b->title,
                    ])->toArray(),
                    'delete_files' => $deleteFiles,
                    'reason' => $reason,
                ];
            }

            $results = [];
            $successCount = 0;
            $failCount = 0;

            foreach ($bookIds as $bookId) {
                $result = $this->trashService->moveToTrash((string)$bookId, $deleteFiles);
                $results[$bookId] = $result;

                if ($result['success']) {
                    $successCount++;
                } else {
                    $failCount++;
                }
            }

            Log::info('Books deleted', [
                'total' => count($bookIds),
                'success' => $successCount,
                'failed' => $failCount,
                'reason' => $reason,
            ]);

            return [
                'success' => $failCount === 0,
                'total' => count($bookIds),
                'success_count' => $successCount,
                'fail_count' => $failCount,
                'results' => $results,
            ];
        } catch (\Exception $e) {
            Log::error('delete_books failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    protected function applyBulkUpdates(array $params): array
    {
        try {
            $updates = $params['updates'];
            $previewId = $params['preview_id'] ?? null;
            $confirmed = $params['confirmed'] ?? false;

            if (!$confirmed) {
                return [
                    'success' => false,
                    'error' => 'Confirmation required. Set confirmed=true to apply updates',
                    'requires_confirmation' => true,
                ];
            }

            if (!is_array($updates) || empty($updates)) {
                return ['success' => false, 'error' => 'Updates array is required'];
            }

            $backupBookIds = collect($updates)->pluck('book_id')->unique()->toArray();
            $this->trashService->createBulkBackup($backupBookIds, 'before_bulk_update');

            $results = [];
            $successCount = 0;
            $failCount = 0;

            DB::transaction(function () use ($updates, &$results, &$successCount, &$failCount) {
                foreach ($updates as $update) {
                    try {
                        $bookId = $update['book_id'];
                        $updateData = $update['updates'];

                        $book = Book::find($bookId);
                        if (!$book) {
                            $results[] = ['book_id' => $bookId, 'success' => false, 'error' => 'Book not found'];
                            $failCount++;
                            continue;
                        }

                        foreach ($updateData as $field => $value) {
                            if (in_array($field, ['title', 'description', 'language', 'isbn', 'release_date'])) {
                                $book->$field = $value;
                            }
                        }

                        $book->save();
                        $results[] = ['book_id' => $bookId, 'success' => true];
                        $successCount++;
                    } catch (\Exception $e) {
                        $results[] = ['book_id' => $update['book_id'] ?? null, 'success' => false, 'error' => $e->getMessage()];
                        $failCount++;
                    }
                }
            });

            Log::info('Bulk updates applied', ['total' => count($updates), 'success' => $successCount, 'failed' => $failCount]);

            return [
                'success' => $failCount === 0,
                'total' => count($updates),
                'success_count' => $successCount,
                'fail_count' => $failCount,
                'results' => $results,
            ];
        } catch (\Exception $e) {
            Log::error('apply_bulk_updates failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    protected function fetchExternalMetadata(array $params): array
    {
        try {
            $source = $params['source'];
            $searchQuery = $params['search_query'];

            return match ($source) {
                'audible' => $this->fetchFromAudible($searchQuery),
                'google_books' => $this->fetchFromGoogleBooks($searchQuery),
                'hardcover' => $this->fetchFromHardcover($searchQuery),
                'goodreads' => $this->fetchFromGoodreads($searchQuery),
                default => ['success' => false, 'error' => 'Unknown source'],
            };
        } catch (\Exception $e) {
            Log::error('fetch_external_metadata failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function fetchFromAudible(string $query): array
    {
        return [
            'success' => true,
            'source' => 'audible',
            'message' => 'Audible API integration not yet implemented',
            'results' => [],
        ];
    }

    private function fetchFromGoogleBooks(string $query): array
    {
        try {
            $response = Http::timeout(10)->get('https://www.googleapis.com/books/v1/volumes', [
                'q' => $query,
                'maxResults' => 5,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $results = collect($data['items'] ?? [])->map(function ($item) {
                    $volumeInfo = $item['volumeInfo'] ?? [];
                    return [
                        'title' => $volumeInfo['title'] ?? null,
                        'authors' => $volumeInfo['authors'] ?? [],
                        'description' => $volumeInfo['description'] ?? null,
                        'isbn' => collect($volumeInfo['industryIdentifiers'] ?? [])->firstWhere('type', 'ISBN_13')['identifier'] ?? null,
                        'published_date' => $volumeInfo['publishedDate'] ?? null,
                        'categories' => $volumeInfo['categories'] ?? [],
                        'cover_url' => $volumeInfo['imageLinks']['thumbnail'] ?? null,
                    ];
                });

                return [
                    'success' => true,
                    'source' => 'google_books',
                    'results' => $results->toArray(),
                    'total' => count($results),
                ];
            }

            return ['success' => false, 'error' => 'Google Books API request failed'];
        } catch (\Exception $e) {
            Log::error('Google Books fetch failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function fetchFromHardcover(string $query): array
    {
        return [
            'success' => true,
            'source' => 'hardcover',
            'message' => 'Hardcover API integration not yet implemented',
            'results' => [],
        ];
    }

    private function fetchFromGoodreads(string $query): array
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
            ])->timeout(15)->get('https://www.goodreads.com/search', [
                'q' => $query,
            ]);

            if ($response->successful()) {
                $html = $response->body();
                $results = [];

                // Regex patterns for extracting book data from search results
                // Note: Goodreads HTML structure changes, so these are best-effort
                preg_match_all('/<tr itemscope itemtype="http:\/\/schema\.org\/Book">/i', $html, $matches, PREG_OFFSET_CAPTURE);

                $bookRows = $matches[0];

                foreach (array_slice($bookRows, 0, 5) as $index => $match) {
                    $startPos = $match[1];
                    $endPos = isset($bookRows[$index + 1]) ? $bookRows[$index + 1][1] : strlen($html);
                    $rowHtml = substr($html, $startPos, $endPos - $startPos);

                    // Extract Title
                    preg_match('/<a class="bookTitle"[^>]*>.*?<span itemprop="name">(.*?)<\/span>.*?<\/a>/s', $rowHtml, $titleMatch);
                    $title = isset($titleMatch[1]) ? trim(strip_tags($titleMatch[1])) : null;

                    // Extract Author
                    preg_match('/<a class="authorName"[^>]*>.*?<span itemprop="name">(.*?)<\/span>.*?<\/a>/s', $rowHtml, $authorMatch);
                    $author = isset($authorMatch[1]) ? trim(strip_tags($authorMatch[1])) : null;

                    // Extract Cover
                    preg_match('/<img[^>]*src="([^"]+)"[^>]*class="bookCover"[^>]*>/i', $rowHtml, $coverMatch);
                    $coverUrl = $coverMatch[1] ?? null;

                    // Extract Rating
                    preg_match('/minirating">.*?(\d+\.\d+) avg rating/s', $rowHtml, $ratingMatch);
                    $rating = $ratingMatch[1] ?? null;

                    if ($title) {
                        $results[] = [
                            'title' => $title,
                            'authors' => $author ? [$author] : [],
                            'description' => null, // Description not easily available in list view
                            'isbn' => null, // ISBN not easily available in list view
                            'published_date' => null,
                            'categories' => [], // Genre not easily available in list view
                            'cover_url' => $coverUrl,
                            'rating' => $rating,
                            'source_link' => 'https://www.goodreads.com/search?q=' . urlencode($query),
                        ];
                    }
                }

                return [
                    'success' => true,
                    'source' => 'goodreads',
                    'results' => $results,
                    'total' => count($results),
                ];
            }

            return ['success' => false, 'error' => 'Goodreads request failed'];
        } catch (\Exception $e) {
            Log::error('Goodreads fetch failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    protected function downloadCoverImage(array $params): array
    {
        try {
            $bookId = $params['book_id'];
            $imageUrl = $params['image_url'] ?? null;
            $autoFetch = $params['auto_fetch'] ?? false;

            $book = Book::find($bookId);
            if (!$book) {
                return ['success' => false, 'error' => 'Book not found'];
            }

            if (!$imageUrl && $autoFetch) {
                $searchQuery = $book->title;
                if ($book->authors->isNotEmpty()) {
                    $searchQuery .= ' ' . $book->authors->first()->name;
                }

                $metadataResult = $this->fetchFromGoogleBooks($searchQuery);
                if ($metadataResult['success'] && !empty($metadataResult['results'])) {
                    $imageUrl = $metadataResult['results'][0]['cover_url'] ?? null;
                }
            }

            if (!$imageUrl) {
                return ['success' => false, 'error' => 'No image URL provided or found'];
            }

            $response = Http::timeout(15)->get($imageUrl);
            if (!$response->successful()) {
                return ['success' => false, 'error' => 'Failed to download image'];
            }

            $imageData = $response->body();
            $extension = pathinfo(parse_url($imageUrl, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'jpg';
            $filename = "cover_{$bookId}.{$extension}";
            $coverPath = "covers/{$filename}";

            Storage::disk('public')->put($coverPath, $imageData);

            $book->cover_image = $coverPath;
            $book->save();

            Log::info('Cover image downloaded', ['book_id' => $bookId, 'url' => $imageUrl]);

            return [
                'success' => true,
                'book_id' => $bookId,
                'cover_path' => $coverPath,
                'cover_url' => Storage::disk('public')->url($coverPath),
            ];
        } catch (\Exception $e) {
            Log::error('download_cover_image failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    protected function triggerAIProcessing(array $params): array
    {
        try {
            $bookIds = $params['book_ids'];
            $force = $params['force_reprocess'] ?? false;
            $minConfidence = $params['min_confidence'] ?? 0.7;

            $processor = app(AIBookProcessor::class);
            $results = [];
            $successCount = 0;
            $failCount = 0;

            foreach ($bookIds as $bookId) {
                try {
                    $book = Book::find($bookId);
                    if (!$book) {
                        $results[] = ['book_id' => $bookId, 'success' => false, 'error' => 'Book not found'];
                        $failCount++;
                        continue;
                    }

                    if (!$force && $book->ai_processed && ($book->ai_confidence ?? 0) >= $minConfidence) {
                        $results[] = ['book_id' => $bookId, 'success' => true, 'skipped' => true, 'reason' => 'Already processed with sufficient confidence'];
                        $successCount++;
                        continue;
                    }

                    $this->trashService->createBackup((string)$bookId, 'before_ai_processing');

                    $processResult = $processor->processBook($book);

                    $results[] = [
                        'book_id' => $bookId,
                        'success' => true,
                        'processed' => true,
                        'confidence' => $book->fresh()->ai_confidence ?? null,
                    ];
                    $successCount++;
                } catch (\Exception $e) {
                    $results[] = ['book_id' => $bookId, 'success' => false, 'error' => $e->getMessage()];
                    $failCount++;
                }
            }

            Log::info('AI processing triggered', ['total' => count($bookIds), 'success' => $successCount, 'failed' => $failCount]);

            return [
                'success' => $failCount === 0,
                'total' => count($bookIds),
                'success_count' => $successCount,
                'fail_count' => $failCount,
                'results' => $results,
            ];
        } catch (\Exception $e) {
            Log::error('trigger_ai_processing failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    protected function generateNFOFiles(array $params): array
    {
        try {
            $bookIds = $params['book_ids'];
            $format = $params['format'] ?? 'kodi';

            $results = [];
            $successCount = 0;
            $failCount = 0;

            foreach ($bookIds as $bookId) {
                try {
                    $book = Book::with(['authors', 'genres', 'narrators', 'series'])->find($bookId);
                    if (!$book) {
                        $results[] = ['book_id' => $bookId, 'success' => false, 'error' => 'Book not found'];
                        $failCount++;
                        continue;
                    }

                    $nfoContent = $this->generateNFOContent($book, $format);
                    $bookPath = $this->bookRoot . '/' . ltrim($book->directory_path, '/');

                    if (!File::exists($bookPath)) {
                        $results[] = ['book_id' => $bookId, 'success' => false, 'error' => 'Book directory not found'];
                        $failCount++;
                        continue;
                    }

                    $nfoPath = rtrim($bookPath, '/') . '/book.nfo';
                    File::put($nfoPath, $nfoContent);

                    $results[] = ['book_id' => $bookId, 'success' => true, 'nfo_path' => $nfoPath];
                    $successCount++;
                } catch (\Exception $e) {
                    $results[] = ['book_id' => $bookId, 'success' => false, 'error' => $e->getMessage()];
                    $failCount++;
                }
            }

            Log::info('NFO files generated', ['total' => count($bookIds), 'success' => $successCount, 'failed' => $failCount]);

            return [
                'success' => $failCount === 0,
                'total' => count($bookIds),
                'success_count' => $successCount,
                'fail_count' => $failCount,
                'results' => $results,
            ];
        } catch (\Exception $e) {
            Log::error('generate_nfo_files failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function generateNFOContent(Book $book, string $format): string
    {
        $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><book></book>');

        $xml->addChild('title', htmlspecialchars($book->title ?? '', ENT_XML1));
        $xml->addChild('plot', htmlspecialchars($book->description ?? '', ENT_XML1));

        foreach ($book->authors as $author) {
            $xml->addChild('author', htmlspecialchars($author->name, ENT_XML1));
        }

        foreach ($book->genres as $genre) {
            $xml->addChild('genre', htmlspecialchars($genre->name, ENT_XML1));
        }

        foreach ($book->narrators as $narrator) {
            $xml->addChild('narrator', htmlspecialchars($narrator->name, ENT_XML1));
        }

        if ($book->series->isNotEmpty()) {
            $series = $book->series->first();
            $xml->addChild('series', htmlspecialchars($series->name, ENT_XML1));
            if ($series->pivot->series_number) {
                $xml->addChild('series_number', $series->pivot->series_number);
            }
        }

        if ($book->isbn) {
            $xml->addChild('isbn', htmlspecialchars($book->isbn, ENT_XML1));
        }

        if ($book->release_date) {
            $xml->addChild('year', date('Y', strtotime($book->release_date)));
        }

        if ($book->language) {
            $xml->addChild('language', htmlspecialchars($book->language, ENT_XML1));
        }

        if ($book->duration) {
            $xml->addChild('runtime', round($book->duration / 60));
        }

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;
        $dom->loadXML($xml->asXML());

        return $dom->saveXML();
    }

    protected function previewAndExecuteBulkOperation(array $params): array
    {
        $action = $params['action'];
        $operations = $params['operations'];
        $executeMode = $params['execute_mode'] ?? 'preview';
        $previewId = Str::uuid()->toString();

        $previewResults = [];

        foreach ($operations as $operation) {
            $bookId = $operation['book_id'];
            $dbUpdates = $operation['database_updates'] ?? [];
            $dirOps = $operation['directory_operations'] ?? [];
            $fileOps = $operation['file_operations'] ?? [];

            $book = Book::with(['authors', 'genres', 'narrators', 'series'])->find($bookId);
            if (!$book) {
                $previewResults[] = [
                    'book_id' => $bookId,
                    'success' => false,
                    'error' => 'Book not found',
                ];
                continue;
            }

            $changes = [];

            if (!empty($dbUpdates)) {
                foreach ($dbUpdates as $field => $value) {
                    $currentValue = $this->getCurrentFieldValue($book, $field);

                    $changes[] = [
                        'type' => 'database_update',
                        'field' => $field,
                        'current' => $currentValue,
                        'proposed' => $value,
                    ];
                }
            }

            if (!empty($dirOps)) {
                $currentPath = $this->bookRoot . '/' . ltrim($book->directory_path, '/');

                if (isset($dirOps['rename_to'])) {
                    $newPath = dirname($currentPath) . '/' . $dirOps['rename_to'];

                    $changes[] = [
                        'type' => 'directory_rename',
                        'field' => 'directory_name',
                        'current' => basename($currentPath),
                        'proposed' => $dirOps['rename_to'],
                        'current_path' => $currentPath,
                        'proposed_path' => $newPath,
                    ];
                }

                if (isset($dirOps['move_to'])) {
                    $newPath = $dirOps['move_to'];
                    if (!str_starts_with($newPath, '/')) {
                        $newPath = $this->bookRoot . '/' . ltrim($newPath, '/');
                    }

                    $changes[] = [
                        'type' => 'directory_move',
                        'field' => 'directory_path',
                        'current' => $book->directory_path,
                        'proposed' => ltrim(str_replace($this->bookRoot . '/', '', $newPath), '/'),
                        'current_path' => $currentPath,
                        'proposed_path' => $newPath,
                    ];
                }
            }

            if (!empty($fileOps)) {
                $bookPath = $this->bookRoot . '/' . ltrim($book->directory_path, '/');
                $files = File::files($bookPath);

                foreach ($fileOps as $fileOp) {
                    if (isset($fileOp['rename_to'])) {
                        $currentFilename = $fileOp['file_name'];
                        $newFilename = $fileOp['rename_to'];
                        $currentFilepath = $bookPath . '/' . $currentFilename;
                        $newFilepath = $bookPath . '/' . $newFilename;

                        $changes[] = [
                            'type' => 'file_rename',
                            'field' => 'audio_filename',
                            'current' => $currentFilename,
                            'proposed' => $newFilename,
                            'current_path' => $currentFilepath,
                            'proposed_path' => $newFilepath,
                        ];
                    } elseif (isset($fileOp['pattern_replace'])) {
                        $pattern = $fileOp['pattern_replace']['pattern'];
                        $replacement = $fileOp['pattern_replace']['replacement'];
                        $currentFilename = $fileOp['file_name'];
                        $newFilename = str_replace($pattern, $replacement, $currentFilename);
                        $currentFilepath = $bookPath . '/' . $currentFilename;
                        $newFilepath = $bookPath . '/' . $newFilename;

                        $changes[] = [
                            'type' => 'file_pattern_replace',
                            'field' => 'audio_filename',
                            'current' => $currentFilename,
                            'proposed' => $newFilename,
                            'pattern' => $pattern,
                            'replacement' => $replacement,
                            'current_path' => $currentFilepath,
                            'proposed_path' => $newFilepath,
                        ];
                    } elseif (isset($fileOp['delete']) && $fileOp['delete'] === true) {
                        $filename = $fileOp['file_name'];
                        $filepath = $bookPath . '/' . $filename;

                        $changes[] = [
                            'type' => 'file_delete',
                            'field' => 'audio_filename',
                            'current' => $filename,
                            'proposed' => null,
                            'current_path' => $filepath,
                            'proposed_path' => null,
                        ];
                    }
                }
            }



            if (!empty($operation['cover_operations'])) {
                $coverOp = $operation['cover_operations'];
                if (isset($coverOp['update_url'])) {
                    $newUrl = $coverOp['update_url'];
                    $currentCover = $book->cover_image;

                    $changes[] = [
                        'type' => 'cover_update',
                        'field' => 'cover_image',
                        'current' => $currentCover,
                        'proposed' => $newUrl,
                        'backup' => $coverOp['backup_existing'] ?? true,
                    ];
                }
            }

            $previewResults[] = [
                'book_id' => $bookId,
                'title' => $book->title,
                'authors' => $book->authors->pluck('name')->toArray(),
                'series' => $book->series->map(function ($s) {
                    return [
                        'name' => $s->name,
                        'number' => $s->pivot->series_number,
                    ];
                })->toArray(),
                'current_directory' => $book->directory_path,
                'changes' => $changes,
                'change_count' => count($changes),
                'success' => true,
            ];
        }

        $this->previewCache[$previewId] = [
            'action' => $action,
            'operations' => $previewResults,
            'created_at' => now(),
        ];

        $summary = [
            'preview_id' => $previewId,
            'action' => $action,
            'execute_mode' => $executeMode,
            'total_books' => count($operations),
            'successful' => collect($previewResults)->where('success', true)->count(),
            'failed' => collect($previewResults)->where('success', false)->count(),
            'total_changes' => collect($previewResults)->sum('change_count'),
        ];

        if ($executeMode === 'preview' || $executeMode === 'preview_only') {
            return [
                'success' => true,
                'preview_id' => $previewId,
                'mode' => 'preview',
                'summary' => $summary,
                'books' => $previewResults,
                'message' => 'Preview generated. Use execute mode to apply changes.',
            ];
        }

        if ($executeMode === 'execute') {
            $executionResults = [];
            $successCount = 0;
            $failureCount = 0;

            foreach ($previewResults as $preview) {
                if (!$preview['success']) {
                    $executionResults[] = $preview;
                    $failureCount++;
                    continue;
                }

                $bookId = $preview['book_id'];
                $book = Book::find($bookId);
                if (!$book) {
                    continue;
                }

                try {
                    $appliedChanges = [];

                    foreach ($preview['changes'] as $change) {
                        $success = false;

                        if ($change['type'] === 'database_update') {
                            $success = $this->applyDatabaseUpdate($book, $change);
                        } elseif ($change['type'] === 'directory_rename' || $change['type'] === 'directory_move') {
                            $success = $this->applyDirectoryOperation($change);
                        } elseif ($change['type'] === 'file_rename') {
                            $success = $this->applyFileRename($change);
                        } elseif ($change['type'] === 'file_pattern_replace') {
                            $success = $this->applyFilePatternReplace($change);
                        } elseif ($change['type'] === 'file_delete') {
                            $success = $this->applyFileDelete($change);
                        } elseif ($change['type'] === 'cover_update') {
                            $success = $this->applyCoverUpdate($book, $change);
                        }

                        if ($success) {
                            $appliedChanges[] = $change;
                        }
                    }

                    $book->refresh();
                    $executionResults[] = [
                        'book_id' => $bookId,
                        'title' => $book->title,
                        'changes_applied' => count($appliedChanges),
                        'changes_total' => count($preview['changes']),
                        'success' => !empty($appliedChanges),
                        'error' => count($appliedChanges) < count($preview['changes']) ? 'Some changes failed' : null,
                    ];

                    if (!empty($appliedChanges)) {
                        $successCount++;
                    } else {
                        $failureCount++;
                    }
                } catch (\Exception $e) {
                    $executionResults[] = [
                        'book_id' => $bookId,
                        'title' => $book->title ?? 'Unknown',
                        'changes_applied' => 0,
                        'changes_total' => count($preview['changes']),
                        'success' => false,
                        'error' => $e->getMessage(),
                    ];
                    $failureCount++;
                }
            }

            return [
                'success' => $failureCount === 0,
                'mode' => 'execute',
                'summary' => [
                    'total_books' => count($operations),
                    'successful' => $successCount,
                    'failed' => $failureCount,
                    'total_changes' => $summary['total_changes'],
                    'changes_applied' => $successCount,
                ],
                'results' => $executionResults,
            ];
        }

        return [
            'success' => false,
            'error' => 'Invalid execute_mode. Use "preview", "preview_only", or "execute"',
        ];
    }

    protected function getCurrentFieldValue(Book $book, string $field)
    {
        switch ($field) {
            case 'title':
                return $book->title;
            case 'description':
                return $book->description;
            case 'language':
                return $book->language;
            case 'isbn':
                return $book->isbn;
            case 'release_date':
                return $book->release_date ? $book->release_date->format('Y-m-d') : null;
            case 'series_number':
                $series = $book->series->first();
                return $series ? (string)$series->pivot->series_number : null;
            default:
                return null;
        }
    }

    protected function applyDatabaseUpdate(Book $book, array $change): bool
    {
        try {
            $field = $change['field'];
            $value = $change['proposed'];

            switch ($field) {
                case 'title':
                case 'description':
                case 'language':
                    $book->$field = $value;
                    break;

                case 'series_number':
                    $series = $book->series->first();
                    if ($series) {
                        DB::table('book_series')
                            ->where('book_id', $book->id)
                            ->where('series_id', $series->id)
                            ->update(['series_number' => $value]);
                    }
                    break;

                default:
                    return false;
            }

            $book->save();
            return true;
        } catch (\Exception $e) {
            Log::error('Database update failed', [
                'book_id' => $book->id,
                'field' => $field,
                'value' => $value,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    protected function applyDirectoryOperation(array $change): bool
    {
        try {
            $fromPath = $change['current_path'];
            $toPath = $change['proposed_path'];

            if (!File::exists($fromPath)) {
                Log::warning('Source does not exist', ['path' => $fromPath]);
                return false;
            }

            if (File::exists($toPath)) {
                Log::warning('Destination already exists', ['path' => $toPath]);
                return false;
            }

            $success = File::move($fromPath, $toPath);

            if ($success) {
                $bookId = DB::table('books')->where('directory_path', str_replace($this->bookRoot . '/', '', $fromPath))->value('id');
                if ($bookId) {
                    $relativePath = ltrim(str_replace($this->bookRoot, '', $toPath), '/');
                    DB::table('books')->where('id', $bookId)->update(['directory_path' => $relativePath]);
                }
            }

            return $success;
        } catch (\Exception $e) {
            Log::error('Directory operation failed', [
                'from' => $change['current_path'],
                'to' => $change['proposed_path'],
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    protected function applyFileRename(array $change): bool
    {
        try {
            $currentPath = $change['current_path'];
            $newPath = $change['proposed_path'];

            if (!File::exists($currentPath)) {
                Log::warning('Source file does not exist', ['path' => $currentPath]);
                return false;
            }

            if (File::exists($newPath)) {
                Log::warning('Destination file already exists', ['path' => $newPath]);
                return false;
            }

            $success = File::move($currentPath, $newPath);
            return $success;
        } catch (\Exception $e) {
            Log::error('File rename failed', [
                'from' => $change['current_path'],
                'to' => $change['proposed_path'],
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    protected function applyFilePatternReplace(array $change): bool
    {
        try {
            $currentPath = $change['current_path'];
            $newPath = $change['proposed_path'];

            if (!File::exists($currentPath)) {
                Log::warning('Source file does not exist', ['path' => $currentPath]);
                return false;
            }

            if (File::exists($newPath)) {
                Log::warning('Destination file already exists', ['path' => $newPath]);
                return false;
            }

            $success = File::move($currentPath, $newPath);
            return $success;
        } catch (\Exception $e) {
            Log::error('File pattern replace failed', [
                'from' => $change['current_path'],
                'to' => $change['proposed_path'],
                'pattern' => $change['pattern'],
                'replacement' => $change['replacement'],
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    protected function applyFileDelete(array $change): bool
    {
        try {
            $filePath = $change['current_path'];

            if (!File::exists($filePath)) {
                Log::warning('File does not exist', ['path' => $filePath]);
                return false;
            }

            $success = File::delete($filePath);
            return $success;
        } catch (\Exception $e) {
            Log::error('File delete failed', [
                'path' => $change['current_path'],
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    protected function applyCoverUpdate(Book $book, array $change): bool
    {
        try {
            $url = $change['proposed'];
            $backup = $change['backup'] ?? true;

            if ($backup && $book->cover_image && File::exists(public_path($book->cover_image))) {
                $backupPath = $book->cover_image . '.bak-' . time();
                File::copy(public_path($book->cover_image), public_path($backupPath));
            }

            // Reuse download logic
            $result = $this->downloadCoverImage([
                'book_id' => $book->id,
                'image_url' => $url,
            ]);

            return $result['success'];
        } catch (\Exception $e) {
            Log::error('Cover update failed', ['book_id' => $book->id, 'error' => $e->getMessage()]);
            return false;
        }
    }

    protected function extractAndApplyMetadataFromAudioFiles(array $params): array
    {
        try {
            $bookIds = $params['book_ids'] ?? null;
            $seriesId = $params['series_id'] ?? null;
            $limit = $params['limit'] ?? 50;
            $autoApply = $params['auto_apply'] ?? false;

            if ($bookIds) {
                $books = Book::query()->whereIn('id', $bookIds)->get();
            } elseif ($seriesId) {
                $books = Book::query()->whereHas('series', fn ($q) => $q->where('series_id', $seriesId))
                    ->take($limit)
                    ->get();
            } else {
                return ['success' => false, 'error' => 'Must provide book_ids or series_id'];
            }

            $results = [];

            /** @var \App\Models\Book $book */
            foreach ($books as $book) {
                $bookPath = $this->bookRoot . '/' . ltrim($book->directory_path, '/');

                if (!File::exists($bookPath)) {
                    $results[] = [
                        'book_id' => $book->id,
                        'title' => $book->title,
                        'success' => false,
                        'error' => 'Book directory not found',
                    ];
                    continue;
                }

                $metadata = $this->audioAnalyzer->analyzeDirectory($bookPath);

                if (!$metadata) {
                    $results[] = [
                        'book_id' => $book->id,
                        'title' => $book->title,
                        'success' => false,
                        'error' => 'No audio metadata found',
                    ];
                    continue;
                }

                if ($autoApply) {
                    $this->importService->updateBookFromMetadata($book, $metadata, [], []);
                }

                $results[] = [
                    'book_id' => $book->id,
                    'title' => $book->title,
                    'extracted_metadata' => $metadata,
                    'success' => true,
                    'applied' => $autoApply,
                ];
            }

            $summary = [
                'total_books' => count($books),
                'metadata_extracted' => collect($results)->where('success', true)->count(),
                'auto_applied' => $autoApply,
            ];

            return [
                'success' => true,
                'summary' => $summary,
                'books' => $results,
                'message' => $autoApply ? 'Metadata extracted and applied' : 'Preview generated. Use extract_and_apply_metadata_from_audio_files with auto_apply=true to apply.',
            ];
        } catch (\Exception $e) {
            Log::error('extract_and_apply_metadata_from_audio_files failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    protected function mergeAuthors(array $params): array
    {
        $primaryAuthorName = $params['primary_author_name'] ?? null;
        $secondaryAuthorNames = $params['secondary_author_names'] ?? [];
        $moveFiles = $params['move_files'] ?? false;
        $previewOnly = $params['preview_only'] ?? true;

        if (!$primaryAuthorName) {
            return ['success' => false, 'error' => 'Primary author name is required'];
        }

        if (empty($secondaryAuthorNames)) {
            return ['success' => false, 'error' => 'At least one secondary author name is required'];
        }

        $primaryAuthor = Author::where('name', $primaryAuthorName)->first();
        if (!$primaryAuthor) {
            return [
                'success' => false,
                'error' => "Primary author '{$primaryAuthorName}' not found",
            ];
        }

        $secondaryAuthors = Author::whereIn('name', $secondaryAuthorNames)->get();
        if ($secondaryAuthors->isEmpty()) {
            return [
                'success' => false,
                'error' => 'No matching secondary authors found',
            ];
        }

        $foundNames = $secondaryAuthors->pluck('name')->toArray();
        $missingNames = array_diff($secondaryAuthorNames, $foundNames);

        $affectedBooks = Book::whereHas('authors', function ($q) use ($secondaryAuthors) {
            $q->whereIn('authors.id', $secondaryAuthors->pluck('id'));
        })->with(['authors', 'series'])->get();

        $preview = [
            'primary_author' => [
                'id' => $primaryAuthor->id,
                'name' => $primaryAuthor->name,
                'current_book_count' => $primaryAuthor->books()->count(),
            ],
            'secondary_authors' => $secondaryAuthors->map(fn ($a) => [
                'id' => $a->id,
                'name' => $a->name,
                'book_count' => $a->books()->count(),
            ])->toArray(),
            'missing_authors' => $missingNames,
            'affected_books' => $affectedBooks->map(function ($book) use ($primaryAuthor, $moveFiles) {
                $result = [
                    'id' => $book->id,
                    'title' => $book->title,
                    'current_authors' => $book->authors->pluck('name')->toArray(),
                    'current_directory' => $book->directory_path,
                ];

                if ($moveFiles) {
                    $newDirectoryPath = $this->generateNewAuthorDirectoryPath($book, $primaryAuthor->name);
                    $result['new_directory'] = $newDirectoryPath;
                    $result['directory_will_change'] = $book->directory_path !== $newDirectoryPath;
                }

                return $result;
            })->toArray(),
            'total_affected_books' => $affectedBooks->count(),
            'move_files' => $moveFiles,
        ];

        if ($previewOnly) {
            return [
                'success' => true,
                'preview' => $preview,
                'message' => 'Preview generated. Use merge_authors with preview_only=false to execute the merge.',
            ];
        }

        try {
            return DB::transaction(function () use ($primaryAuthor, $secondaryAuthors, $affectedBooks, $moveFiles, $preview) {
                $documentStore = app(DocumentStoreServiceInterface::class);
                $secondaryAuthorIds = $secondaryAuthors->pluck('id')->map(fn ($id) => (string) $id)->toArray();

                $affectedCount = $documentStore->mergeAuthors((string) $primaryAuthor->id, $secondaryAuthorIds);

                $filesMoved = 0;
                $fileMoveErrors = [];

                if ($moveFiles) {
                    $moveService = app(BookDirectoryMoveService::class);

                    foreach ($affectedBooks as $book) {
                        $book->refresh();
                        $newDirectoryPath = $this->generateNewAuthorDirectoryPath($book, $primaryAuthor->name);

                        if ($book->directory_path !== $newDirectoryPath) {
                            $result = $moveService->moveBookDirectoryContents(
                                $book->directory_path,
                                $newDirectoryPath,
                                $book->cover_image ? basename($book->cover_image) : null
                            );

                            if ($result['moved']) {
                                $book->directory_path = $result['directoryPath'] ?? $newDirectoryPath;
                                if (isset($result['coverImage'])) {
                                    $book->cover_image = $book->directory_path . '/' . $result['coverImage'];
                                }
                                $book->save();
                                $filesMoved++;
                            } else {
                                $fileMoveErrors[] = [
                                    'book_id' => $book->id,
                                    'title' => $book->title,
                                    'error' => $result['error'] ?? 'Unknown error',
                                ];
                            }
                        }
                    }
                }

                return [
                    'success' => true,
                    'message' => "Merged {$affectedCount} book(s) from " . count($secondaryAuthorIds) . " author(s) into '{$primaryAuthor->name}'",
                    'affected_books' => $affectedCount,
                    'authors_deleted' => count($secondaryAuthorIds),
                    'files_moved' => $filesMoved,
                    'file_move_errors' => $fileMoveErrors,
                    'preview' => $preview,
                ];
            });
        } catch (\Exception $e) {
            Log::error('merge_authors failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    protected function generateNewAuthorDirectoryPath(Book $book, string $primaryAuthorName): string
    {
        $authorSlug = Str::slug($primaryAuthorName);

        $series = $book->series->first();
        if ($series) {
            $seriesSlug = Str::slug($series->name);
            $seriesNumber = $series->pivot->series_number ?? null;
            $titleSlug = Str::slug($book->title);

            if ($seriesNumber !== null) {
                return "{$authorSlug}/{$seriesSlug}/{$seriesNumber}-{$titleSlug}";
            }

            return "{$authorSlug}/{$seriesSlug}/{$titleSlug}";
        }

        $titleSlug = Str::slug($book->title);

        return "{$authorSlug}/{$titleSlug}";
    }

    protected function mergeSeries(array $params): array
    {
        $primarySeriesName = $params['primary_series_name'] ?? null;
        $secondarySeriesNames = $params['secondary_series_names'] ?? [];
        $moveFiles = $params['move_files'] ?? false;
        $previewOnly = $params['preview_only'] ?? true;

        if (!$primarySeriesName) {
            return ['success' => false, 'error' => 'Primary series name is required'];
        }

        if (empty($secondarySeriesNames)) {
            return ['success' => false, 'error' => 'At least one secondary series name is required'];
        }

        $primarySeries = Series::where('name', $primarySeriesName)->first();
        if (!$primarySeries) {
            return [
                'success' => false,
                'error' => "Primary series '{$primarySeriesName}' not found",
            ];
        }

        $secondarySeries = Series::whereIn('name', $secondarySeriesNames)->get();
        if ($secondarySeries->isEmpty()) {
            return [
                'success' => false,
                'error' => 'No matching secondary series found',
            ];
        }

        $foundNames = $secondarySeries->pluck('name')->toArray();
        $missingNames = array_diff($secondarySeriesNames, $foundNames);

        $affectedBooks = Book::whereHas('series', function ($q) use ($secondarySeries) {
            $q->whereIn('series.id', $secondarySeries->pluck('id'));
        })->with(['authors', 'series'])->get();

        $preview = [
            'primary_series' => [
                'id' => $primarySeries->id,
                'name' => $primarySeries->name,
                'current_book_count' => $primarySeries->books()->count(),
            ],
            'secondary_series' => $secondarySeries->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'book_count' => $s->books()->count(),
            ])->toArray(),
            'missing_series' => $missingNames,
            'affected_books' => $affectedBooks->map(function ($book) use ($primarySeries, $moveFiles) {
                $result = [
                    'id' => $book->id,
                    'title' => $book->title,
                    'current_series' => $book->series->pluck('name')->toArray(),
                    'current_directory' => $book->directory_path,
                ];

                if ($moveFiles) {
                    $newDirectoryPath = $this->generateNewSeriesDirectoryPath($book, $primarySeries->name);
                    $result['new_directory'] = $newDirectoryPath;
                    $result['directory_will_change'] = $book->directory_path !== $newDirectoryPath;
                }

                return $result;
            })->toArray(),
            'total_affected_books' => $affectedBooks->count(),
            'move_files' => $moveFiles,
        ];

        if ($previewOnly) {
            return [
                'success' => true,
                'preview' => $preview,
                'message' => 'Preview generated. Use merge_series with preview_only=false to execute the merge.',
            ];
        }

        try {
            return DB::transaction(function () use ($primarySeries, $secondarySeries, $affectedBooks, $moveFiles, $preview) {
                $documentStore = app(DocumentStoreServiceInterface::class);
                $secondarySeriesIds = $secondarySeries->pluck('id')->map(fn ($id) => (string) $id)->toArray();

                $affectedCount = $documentStore->mergeSeries((string) $primarySeries->id, $secondarySeriesIds);

                $filesMoved = 0;
                $fileMoveErrors = [];

                if ($moveFiles) {
                    $moveService = app(BookDirectoryMoveService::class);

                    foreach ($affectedBooks as $book) {
                        $book->refresh();
                        $newDirectoryPath = $this->generateNewSeriesDirectoryPath($book, $primarySeries->name);

                        if ($book->directory_path !== $newDirectoryPath) {
                            $result = $moveService->moveBookDirectoryContents(
                                $book->directory_path,
                                $newDirectoryPath,
                                $book->cover_image ? basename($book->cover_image) : null
                            );

                            if ($result['moved']) {
                                $book->directory_path = $result['directoryPath'] ?? $newDirectoryPath;
                                if (isset($result['coverImage'])) {
                                    $book->cover_image = $book->directory_path . '/' . $result['coverImage'];
                                }
                                $book->save();
                                $filesMoved++;
                            } else {
                                $fileMoveErrors[] = [
                                    'book_id' => $book->id,
                                    'title' => $book->title,
                                    'error' => $result['error'] ?? 'Unknown error',
                                ];
                            }
                        }
                    }
                }

                return [
                    'success' => true,
                    'message' => "Merged {$affectedCount} book(s) from " . count($secondarySeriesIds) . " series into '{$primarySeries->name}'",
                    'affected_books' => $affectedCount,
                    'series_deleted' => count($secondarySeriesIds),
                    'files_moved' => $filesMoved,
                    'file_move_errors' => $fileMoveErrors,
                    'preview' => $preview,
                ];
            });
        } catch (\Exception $e) {
            Log::error('merge_series failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    protected function generateNewSeriesDirectoryPath(Book $book, string $primarySeriesName): string
    {
        $author = $book->authors->first();
        $authorSlug = $author ? Str::slug($author->name) : 'unknown-author';
        $seriesSlug = Str::slug($primarySeriesName);

        $seriesPivot = $book->series->first()?->pivot;
        $seriesNumber = $seriesPivot ? $seriesPivot->getAttribute('series_number') : null;
        $titleSlug = Str::slug($book->title);

        if ($seriesNumber !== null) {
            return "{$authorSlug}/{$seriesSlug}/{$seriesNumber}-{$titleSlug}";
        }

        return "{$authorSlug}/{$seriesSlug}/{$titleSlug}";
    }
}
