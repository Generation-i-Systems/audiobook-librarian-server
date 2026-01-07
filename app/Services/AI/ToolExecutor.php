<?php

namespace App\Services\AI;

use App\Models\Author;
use App\Models\Book;
use App\Models\Genre;
use App\Models\Narrator;
use App\Models\Series;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ToolExecutor
{
    protected string $bookRoot;
    protected array $previewCache = [];

    public function __construct()
    {
        $this->bookRoot = rtrim(config('app.book_root', '/media/lyra_data1/audiobooks/books'), '/');
    }

    public function execute(string $toolName, array $parameters): array
    {
        try {
            Log::info('Executing tool', [
                'tool' => $toolName,
                'parameters' => $parameters,
            ]);

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
                        'number' => $s->pivot->series_number,
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

        $books = Book::whereHas('series', fn ($q) => $q->where('series_id', $series->id))
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
            $books = Book::whereIn('id', $params['book_ids'])->get();
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

        $preview = $previewId && isset($this->previewCache[$previewId])
            ? $this->previewCache[$previewId]['moves']
            : null;

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
            $books = Book::whereHas('series', fn ($q) => $q->where('series_id', $params['series_id']))
                ->with(['authors', 'series'])
                ->get();
        } elseif (isset($params['book_ids'])) {
            $books = Book::whereIn('id', $params['book_ids'])->with(['authors', 'series'])->get();
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

        $books = Book::whereIn('id', $bookIds)->with(['authors', 'genres', 'narrators', 'series'])->get();

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
            'books_with_ai_processing' => Book::where('ai_processed', true)->count(),
            'books_needing_review' => Book::where('needs_review', true)->count(),
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
                'no_description' => Book::whereNull('description')->orWhere('description', '')->limit($limit)->get(['id', 'title'])->toArray(),
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
                $books = Book::whereIn('id', $bookIds)->with(['authors', 'series'])->get();

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
                $books = Book::whereIn('id', $bookIds)->with(['authors'])->get();

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
            $missing['no_cover'] = Book::whereNull('cover_image')->orWhere('cover_image', '')->limit($limit)->get(['id', 'title', 'directory_path'])->toArray();
        }

        if (in_array('all', $metadataTypes) || in_array('description', $metadataTypes)) {
            $missing['no_description'] = Book::whereNull('description')->orWhere('description', '')->limit($limit)->get(['id', 'title', 'directory_path'])->toArray();
        }

        if (in_array('all', $metadataTypes) || in_array('isbn', $metadataTypes)) {
            $missing['no_isbn'] = Book::whereNull('isbn')->orWhere('isbn', '')->limit($limit)->get(['id', 'title', 'directory_path'])->toArray();
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

                $recommendations = Book::where('id', '!=', $bookId)
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
                'ai_processed_books' => Book::where('ai_processed', true)->count(),
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
                'books_missing_description' => Book::whereNull('description')->orWhere('description', '')->count(),
                'books_needing_review' => Book::where('needs_review', true)->count(),
                'ai_unprocessed' => Book::where('ai_processed', false)->count(),
            ];
        }

        return [
            'success' => true,
            'analysis' => $analysis,
        ];
    }
}
