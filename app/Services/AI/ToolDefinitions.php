<?php

namespace App\Services\AI;

class ToolDefinitions
{
    public static function getAllTools(): array
    {
        return [
            self::searchBooks(),
            self::analyzeSeries(),
            self::getSeriesDetails(),
            self::getBookDetails(),
            self::searchAuthors(),
            self::searchGenres(),
            self::searchNarrators(),
            self::listDirectory(),
            self::scanBookFiles(),
            self::checkFilesExist(),
            self::previewFileMove(),
            self::executeFileMove(),
            self::patternRenamePreview(),
            self::bulkUpdatePreview(),
            self::executeAdvancedQuery(),
        ];
    }

    public static function getDatabaseTools(): array
    {
        return [
            self::searchBooks(),
            self::analyzeSeries(),
            self::getSeriesDetails(),
            self::getBookDetails(),
            self::searchAuthors(),
            self::searchGenres(),
            self::searchNarrators(),
            self::executeAdvancedQuery(),
        ];
    }

    public static function getFilesystemTools(): array
    {
        return [
            self::listDirectory(),
            self::scanBookFiles(),
            self::checkFilesExist(),
            self::previewFileMove(),
            self::executeFileMove(),
        ];
    }

    protected static function searchBooks(): array
    {
        return [
            'name' => 'search_books',
            'description' => 'Search for books with flexible criteria including title, author, series, genre, narrator, or any combination. Returns detailed book information with all relationships.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'query' => [
                        'type' => 'string',
                        'description' => 'General search query to match against title, author, or series',
                    ],
                    'title' => [
                        'type' => 'string',
                        'description' => 'Search by book title (partial match)',
                    ],
                    'author' => [
                        'type' => 'string',
                        'description' => 'Search by author name (partial match)',
                    ],
                    'series' => [
                        'type' => 'string',
                        'description' => 'Search by series name (partial match)',
                    ],
                    'genre' => [
                        'type' => 'string',
                        'description' => 'Filter by genre name',
                    ],
                    'narrator' => [
                        'type' => 'string',
                        'description' => 'Search by narrator name (partial match)',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'Maximum number of results to return (default: 100)',
                    ],
                ],
            ],
        ];
    }

    protected static function analyzeSeries(): array
    {
        return [
            'name' => 'analyze_series',
            'description' => 'Analyze a book series to find missing books (gaps in numbering), duplicate entries, out-of-order books, and naming patterns. Returns comprehensive series analysis.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'series_name' => [
                        'type' => 'string',
                        'description' => 'Name of the series to analyze (partial match supported)',
                    ],
                    'series_id' => [
                        'type' => 'integer',
                        'description' => 'ID of the series to analyze (if known)',
                    ],
                ],
            ],
        ];
    }

    protected static function getSeriesDetails(): array
    {
        return [
            'name' => 'get_series_details',
            'description' => 'Get detailed information about a series including all books, their order, metadata, and statistics. Useful for understanding series structure before operations.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'series_name' => [
                        'type' => 'string',
                        'description' => 'Name of the series',
                    ],
                    'series_id' => [
                        'type' => 'integer',
                        'description' => 'ID of the series (if known)',
                    ],
                    'include_books' => [
                        'type' => 'boolean',
                        'description' => 'Include full book details (default: true)',
                    ],
                ],
            ],
        ];
    }

    protected static function getBookDetails(): array
    {
        return [
            'name' => 'get_book_details',
            'description' => 'Get complete details about a specific book including all metadata, relationships (authors, genres, series), file information, and AI processing status.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'book_id' => [
                        'type' => 'integer',
                        'description' => 'ID of the book',
                    ],
                    'title' => [
                        'type' => 'string',
                        'description' => 'Book title (if ID not known)',
                    ],
                ],
                'required' => [],
            ],
        ];
    }

    protected static function searchAuthors(): array
    {
        return [
            'name' => 'search_authors',
            'description' => 'Search for authors and optionally include their book count and book list.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'name' => [
                        'type' => 'string',
                        'description' => 'Author name to search for (partial match)',
                    ],
                    'include_books' => [
                        'type' => 'boolean',
                        'description' => 'Include list of books by this author (default: false)',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'Maximum number of results (default: 50)',
                    ],
                ],
            ],
        ];
    }

    protected static function searchGenres(): array
    {
        return [
            'name' => 'search_genres',
            'description' => 'Search for genres and get book counts. Useful for understanding genre distribution.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'name' => [
                        'type' => 'string',
                        'description' => 'Genre name to search for (partial match)',
                    ],
                    'include_books' => [
                        'type' => 'boolean',
                        'description' => 'Include list of books in this genre (default: false)',
                    ],
                ],
            ],
        ];
    }

    protected static function searchNarrators(): array
    {
        return [
            'name' => 'search_narrators',
            'description' => 'Search for narrators and optionally include their narration count and book list.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'name' => [
                        'type' => 'string',
                        'description' => 'Narrator name to search for (partial match)',
                    ],
                    'include_books' => [
                        'type' => 'boolean',
                        'description' => 'Include list of books narrated (default: false)',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'Maximum number of results (default: 50)',
                    ],
                ],
            ],
        ];
    }

    protected static function listDirectory(): array
    {
        return [
            'name' => 'list_directory',
            'description' => 'List files and subdirectories in a given path. Useful for exploring book file structure and finding audio files.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'path' => [
                        'type' => 'string',
                        'description' => 'Directory path to list (relative to book root or absolute)',
                    ],
                    'recursive' => [
                        'type' => 'boolean',
                        'description' => 'List subdirectories recursively (default: false)',
                    ],
                    'file_types' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Filter by file extensions (e.g., ["m4b", "mp3"])',
                    ],
                ],
                'required' => ['path'],
            ],
        ];
    }

    protected static function scanBookFiles(): array
    {
        return [
            'name' => 'scan_book_files',
            'description' => 'Scan a book directory to find audio files and get detailed file information including size, format, and naming patterns.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'book_id' => [
                        'type' => 'integer',
                        'description' => 'Book ID to scan files for',
                    ],
                    'directory_path' => [
                        'type' => 'string',
                        'description' => 'Directory path to scan (if book_id not provided)',
                    ],
                ],
            ],
        ];
    }

    protected static function checkFilesExist(): array
    {
        return [
            'name' => 'check_files_exist',
            'description' => 'Check if files exist for one or more books. Useful for validating database records match filesystem.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'book_ids' => [
                        'type' => 'array',
                        'items' => ['type' => 'integer'],
                        'description' => 'Array of book IDs to check',
                    ],
                    'paths' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Array of file paths to check',
                    ],
                ],
            ],
        ];
    }

    protected static function previewFileMove(): array
    {
        return [
            'name' => 'preview_file_move',
            'description' => 'Preview file move operations before executing them. Shows source, destination, and potential conflicts. ALWAYS use this before execute_file_move.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'moves' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'book_id' => ['type' => 'integer'],
                                'from_path' => ['type' => 'string'],
                                'to_path' => ['type' => 'string'],
                            ],
                        ],
                        'description' => 'Array of move operations to preview',
                    ],
                ],
                'required' => ['moves'],
            ],
        ];
    }

    protected static function executeFileMove(): array
    {
        return [
            'name' => 'execute_file_move',
            'description' => 'Execute file move operations. MUST be preceded by preview_file_move and user confirmation. Updates database records after moving files.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'preview_id' => [
                        'type' => 'string',
                        'description' => 'ID from preview_file_move to execute',
                    ],
                    'confirmed_moves' => [
                        'type' => 'array',
                        'items' => ['type' => 'integer'],
                        'description' => 'Array of book IDs from preview that user confirmed',
                    ],
                ],
                'required' => ['confirmed_moves'],
            ],
        ];
    }

    protected static function patternRenamePreview(): array
    {
        return [
            'name' => 'pattern_rename_preview',
            'description' => 'Preview renaming books using a template pattern. Supports variables like {series}, {number}, {title}, {author}. Shows before/after for user approval.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'series_id' => [
                        'type' => 'integer',
                        'description' => 'Series ID to rename books in',
                    ],
                    'book_ids' => [
                        'type' => 'array',
                        'items' => ['type' => 'integer'],
                        'description' => 'Specific book IDs to rename',
                    ],
                    'template' => [
                        'type' => 'string',
                        'description' => 'Rename template using variables: {series}, {number}, {title}, {author}, {narrator}',
                    ],
                    'apply_to' => [
                        'type' => 'string',
                        'enum' => ['title', 'directory', 'both'],
                        'description' => 'What to rename: title field, directory path, or both',
                    ],
                ],
                'required' => ['template'],
            ],
        ];
    }

    protected static function bulkUpdatePreview(): array
    {
        return [
            'name' => 'bulk_update_preview',
            'description' => 'Preview bulk metadata updates before applying them. Can update genres, authors, series, or other metadata for multiple books.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'book_ids' => [
                        'type' => 'array',
                        'items' => ['type' => 'integer'],
                        'description' => 'Book IDs to update',
                    ],
                    'updates' => [
                        'type' => 'object',
                        'description' => 'Metadata updates to apply (genre, author, series, etc.)',
                    ],
                ],
                'required' => ['book_ids', 'updates'],
            ],
        ];
    }

    protected static function executeAdvancedQuery(): array
    {
        return [
            'name' => 'execute_advanced_query',
            'description' => 'Execute complex custom queries for operations not covered by other tools. Can perform aggregations, complex joins, and statistical analysis. Use with caution.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'description' => [
                        'type' => 'string',
                        'description' => 'Human-readable description of what this query does',
                    ],
                    'query_type' => [
                        'type' => 'string',
                        'enum' => ['count', 'aggregate', 'list', 'statistics'],
                        'description' => 'Type of query operation',
                    ],
                    'parameters' => [
                        'type' => 'object',
                        'description' => 'Query parameters and filters',
                    ],
                ],
                'required' => ['description', 'query_type'],
            ],
        ];
    }
}
