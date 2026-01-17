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
            self::analyzeDataQuality(),
            self::findDuplicateBooks(),
            self::findMissingMetadata(),
            self::getRecommendations(),
            self::analyzeCollection(),
            self::readAudioMetadata(),
            self::createBook(),
            self::updateBookMetadata(),
            self::deleteBooks(),
            self::applyBulkUpdates(),
            self::fetchExternalMetadata(),
            self::downloadCoverImage(),
            self::triggerAiProcessing(),
            self::generateNfoFiles(),
            self::previewAndExecuteBulkOperation(),
            self::extractAndApplyMetadataFromAudioFiles(),
            self::mergeAuthors(),
            self::mergeSeries(),
        ];
    }

    protected static function previewAndExecuteBulkOperation(): array
    {
        return [
            'name' => 'preview_and_execute_bulk_operation',
            'description' => 'Preview and execute flexible bulk operations across database, directories, and audio files. Supports pattern-based renaming, file-level operations, and database updates. Enables complex workflows like removing "r" from audio filenames, zero-padding directories while fixing DB series numbers, or batch renaming files.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'action' => [
                        'type' => 'string',
                        'description' => 'Human-readable description (e.g., "Remove letter r from all audio filenames")',
                    ],
                    'operations' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'book_id' => ['type' => 'integer'],
                                'database_updates' => [
                                    'type' => 'object',
                                    'description' => 'Database fields to update',
                                ],
                                'directory_operations' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'rename_to' => ['type' => 'string', 'description' => 'Rename directory to this name'],
                                        'move_to' => ['type' => 'string', 'description' => 'Move directory to this path'],
                                    ],
                                ],
                                'file_operations' => [
                                    'type' => 'array',
                                    'items' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'file_name' => ['type' => 'string', 'description' => 'Current filename to match'],
                                            'rename_to' => ['type' => 'string', 'description' => 'New filename'],
                                            'pattern_replace' => [
                                                'type' => 'object',
                                                'properties' => [
                                                    'pattern' => ['type' => 'string'],
                                                    'replacement' => ['type' => 'string'],
                                                ],
                                            ],
                                            'delete' => ['type' => 'boolean', 'description' => 'Delete this file'],
                                        ],
                                    ],
                                ],
                                'cover_operations' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'update_url' => ['type' => 'string', 'description' => 'URL to download new cover image from'],
                                        'backup_existing' => ['type' => 'boolean', 'description' => 'Whether to backup existing cover (default: true)'],
                                    ],
                                ],
                            ],
                        ],
                        'description' => 'Array of coordinated operations per book',
                    ],
                    'execute_mode' => [
                        'type' => 'string',
                        'enum' => ['preview', 'preview_only', 'execute'],
                        'description' => 'preview: generate detailed before/after; execute: apply confirmed changes',
                    ],
                ],
                'required' => ['action', 'operations'],
            ],
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

    protected static function analyzeDataQuality(): array
    {
        return [
            'name' => 'analyze_data_quality',
            'description' => 'Analyze library data quality, finding books with missing or incomplete metadata, orphaned records, filesystem mismatches, and other data integrity issues.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'check_types' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'string',
                            'enum' => ['missing_metadata', 'orphaned_records', 'filesystem_mismatches', 'invalid_data', 'all'],
                        ],
                        'description' => 'Types of quality checks to perform (default: all)',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'Maximum number of issues to return per check type',
                    ],
                ],
            ],
        ];
    }

    protected static function findDuplicateBooks(): array
    {
        return [
            'name' => 'find_duplicate_books',
            'description' => 'Find potential duplicate books across the entire library based on title similarity, author matching, ISBN, or ASIN. Useful for library cleanup.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'method' => [
                        'type' => 'string',
                        'enum' => ['exact_title', 'similar_title', 'isbn', 'asin', 'all'],
                        'description' => 'Method to use for duplicate detection (default: all)',
                    ],
                    'threshold' => [
                        'type' => 'number',
                        'description' => 'Similarity threshold for fuzzy matching (0-1, default: 0.85)',
                    ],
                    'include_series_books' => [
                        'type' => 'boolean',
                        'description' => 'Include books in same series (may have similar titles) (default: false)',
                    ],
                ],
            ],
        ];
    }

    protected static function findMissingMetadata(): array
    {
        return [
            'name' => 'find_missing_metadata',
            'description' => 'Find books with missing or incomplete metadata such as no author, no genre, no cover, no description, etc.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'metadata_types' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'string',
                            'enum' => ['author', 'genre', 'series', 'narrator', 'cover', 'description', 'publisher', 'isbn', 'all'],
                        ],
                        'description' => 'Types of missing metadata to find (default: all)',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'Maximum results per metadata type (default: 50)',
                    ],
                ],
            ],
        ];
    }

    protected static function getRecommendations(): array
    {
        return [
            'name' => 'get_recommendations',
            'description' => 'Get book recommendations based on a given book, author, genre, or series. Uses collaborative filtering based on shared characteristics.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'based_on' => [
                        'type' => 'string',
                        'enum' => ['book', 'author', 'genre', 'series'],
                        'description' => 'What to base recommendations on',
                    ],
                    'book_id' => [
                        'type' => 'integer',
                        'description' => 'Book ID (if based_on is "book")',
                    ],
                    'author_name' => [
                        'type' => 'string',
                        'description' => 'Author name (if based_on is "author")',
                    ],
                    'genre_name' => [
                        'type' => 'string',
                        'description' => 'Genre name (if based_on is "genre")',
                    ],
                    'series_name' => [
                        'type' => 'string',
                        'description' => 'Series name (if based_on is "series")',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'Number of recommendations (default: 10)',
                    ],
                ],
                'required' => ['based_on'],
            ],
        ];
    }

    protected static function analyzeCollection(): array
    {
        return [
            'name' => 'analyze_collection',
            'description' => 'Analyze the entire library collection with comprehensive statistics including genre distribution, author counts, series completion rates, reading time estimates, and more.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'analysis_types' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'string',
                            'enum' => ['overview', 'genres', 'authors', 'series', 'narrators', 'publishers', 'quality', 'growth', 'all'],
                        ],
                        'description' => 'Types of analysis to perform (default: overview)',
                    ],
                    'include_charts_data' => [
                        'type' => 'boolean',
                        'description' => 'Include data formatted for charting (default: false)',
                    ],
                ],
            ],
        ];
    }

    protected static function readAudioMetadata(): array
    {
        return [
            'name' => 'read_audio_metadata',
            'description' => 'Read ID3 tags and technical metadata from audio files including title, artist, album, duration, bitrate, format, and chapters. Useful for validating database metadata against actual file contents.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'book_id' => [
                        'type' => 'integer',
                        'description' => 'Book ID to read metadata for',
                    ],
                    'file_path' => [
                        'type' => 'string',
                        'description' => 'Direct file path to read (if book_id not provided)',
                    ],
                    'include_chapters' => [
                        'type' => 'boolean',
                        'description' => 'Include chapter information if available (default: false)',
                    ],
                ],
            ],
        ];
    }

    protected static function createBook(): array
    {
        return [
            'name' => 'create_book',
            'description' => 'Create a new book in the database from provided metadata or by discovering from a directory. IMPORTANT: This creates database records and should be used carefully.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'title' => [
                        'type' => 'string',
                        'description' => 'Book title',
                    ],
                    'authors' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Author names',
                    ],
                    'genres' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Genre names',
                    ],
                    'directory_path' => [
                        'type' => 'string',
                        'description' => 'Directory path containing book files',
                    ],
                    'metadata' => [
                        'type' => 'object',
                        'description' => 'Additional metadata (narrator, series, publisher, etc.)',
                    ],
                    'auto_discover' => [
                        'type' => 'boolean',
                        'description' => 'Auto-discover metadata from directory and files (default: false)',
                    ],
                ],
                'required' => ['title', 'directory_path'],
            ],
        ];
    }

    protected static function updateBookMetadata(): array
    {
        return [
            'name' => 'update_book_metadata',
            'description' => 'Update metadata fields for one or more books. Changes are applied immediately to the database. Use with caution.',
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
                        'description' => 'Fields to update (title, description, release_date, language, isbn, etc.)',
                    ],
                    'add_authors' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Author names to add (creates if not exists)',
                    ],
                    'add_genres' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Genre names to add',
                    ],
                    'add_narrators' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Narrator names to add',
                    ],
                    'preview_only' => [
                        'type' => 'boolean',
                        'description' => 'Show preview without applying changes (default: true)',
                    ],
                ],
                'required' => ['book_ids', 'updates'],
            ],
        ];
    }

    protected static function deleteBooks(): array
    {
        return [
            'name' => 'delete_books',
            'description' => 'Delete books from the database. DESTRUCTIVE OPERATION - use with extreme caution. Can optionally delete physical files too.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'book_ids' => [
                        'type' => 'array',
                        'items' => ['type' => 'integer'],
                        'description' => 'Book IDs to delete',
                    ],
                    'delete_files' => [
                        'type' => 'boolean',
                        'description' => 'Also delete physical files from filesystem (default: false)',
                    ],
                    'reason' => [
                        'type' => 'string',
                        'description' => 'Reason for deletion (for audit log)',
                    ],
                    'confirmed' => [
                        'type' => 'boolean',
                        'description' => 'User confirmation required (default: false)',
                    ],
                ],
                'required' => ['book_ids', 'reason', 'confirmed'],
            ],
        ];
    }

    protected static function applyBulkUpdates(): array
    {
        return [
            'name' => 'apply_bulk_updates',
            'description' => 'Execute previously previewed bulk updates from pattern_rename_preview or bulk_update_preview. Applies confirmed changes to database and optionally filesystem.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'operation_type' => [
                        'type' => 'string',
                        'enum' => ['rename', 'metadata_update', 'file_move'],
                        'description' => 'Type of bulk operation to apply',
                    ],
                    'book_ids' => [
                        'type' => 'array',
                        'items' => ['type' => 'integer'],
                        'description' => 'Book IDs from preview to apply changes to',
                    ],
                    'changes' => [
                        'type' => 'object',
                        'description' => 'Changes to apply (from preview)',
                    ],
                    'confirmed' => [
                        'type' => 'boolean',
                        'description' => 'User confirmation required',
                    ],
                ],
                'required' => ['operation_type', 'book_ids', 'confirmed'],
            ],
        ];
    }

    protected static function fetchExternalMetadata(): array
    {
        return [
            'name' => 'fetch_external_metadata',
            'description' => 'Fetch enriched metadata from external APIs including Audible, Google Books, and Hardcover. Can fetch covers, descriptions, series info, and more.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'book_id' => [
                        'type' => 'integer',
                        'description' => 'Book ID to enrich',
                    ],
                    'isbn' => [
                        'type' => 'string',
                        'description' => 'ISBN to search with (if book_id not provided)',
                    ],
                    'title' => [
                        'type' => 'string',
                        'description' => 'Book title for search',
                    ],
                    'author' => [
                        'type' => 'string',
                        'description' => 'Author name for search',
                    ],
                    'sources' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'string',
                            'enum' => ['audible', 'google_books', 'hardcover', 'all'],
                        ],
                        'description' => 'External sources to query (default: all)',
                    ],
                    'auto_apply' => [
                        'type' => 'boolean',
                        'description' => 'Automatically apply fetched metadata (default: false)',
                    ],
                ],
            ],
        ];
    }

    protected static function downloadCoverImage(): array
    {
        return [
            'name' => 'download_cover_image',
            'description' => 'Download and save cover image for a book from a URL or by auto-fetching from external sources.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'book_id' => [
                        'type' => 'integer',
                        'description' => 'Book ID to download cover for',
                    ],
                    'image_url' => [
                        'type' => 'string',
                        'description' => 'Direct URL to cover image',
                    ],
                    'auto_fetch' => [
                        'type' => 'boolean',
                        'description' => 'Auto-fetch cover from external sources (default: true if no URL)',
                    ],
                    'replace_existing' => [
                        'type' => 'boolean',
                        'description' => 'Replace existing cover (default: false)',
                    ],
                ],
                'required' => ['book_id'],
            ],
        ];
    }

    protected static function triggerAiProcessing(): array
    {
        return [
            'name' => 'trigger_ai_processing',
            'description' => 'Run AI metadata extraction/improvement on specified books using AIBookProcessor. Can automatically apply high-confidence suggestions.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'book_ids' => [
                        'type' => 'array',
                        'items' => ['type' => 'integer'],
                        'description' => 'Book IDs to process',
                    ],
                    'confidence_threshold' => [
                        'type' => 'number',
                        'description' => 'Minimum confidence to auto-apply (0-100, default: 90)',
                    ],
                    'apply_immediately' => [
                        'type' => 'boolean',
                        'description' => 'Apply changes above threshold immediately (default: false)',
                    ],
                    'force_reprocess' => [
                        'type' => 'boolean',
                        'description' => 'Reprocess even if already processed (default: false)',
                    ],
                ],
                'required' => ['book_ids'],
            ],
        ];
    }

    protected static function generateNfoFiles(): array
    {
        return [
            'name' => 'generate_nfo_files',
            'description' => 'Generate .nfo sidecar files for books based on database metadata. Uses standard Kodi/Plex format.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'book_ids' => [
                        'type' => 'array',
                        'items' => ['type' => 'integer'],
                        'description' => 'Book IDs to generate NFO files for',
                    ],
                    'template' => [
                        'type' => 'string',
                        'enum' => ['kodi', 'plex', 'basic'],
                        'description' => 'NFO template format (default: kodi)',
                    ],
                    'overwrite_existing' => [
                        'type' => 'boolean',
                        'description' => 'Overwrite existing NFO files (default: false)',
                    ],
                ],
                'required' => ['book_ids'],
            ],
        ];
    }

    protected static function extractAndApplyMetadataFromAudioFiles(): array
    {
        return [
            'name' => 'extract_and_apply_metadata_from_audio_files',
            'description' => 'Extract metadata from audio file ID3 tags using existing AudioFileAnalyzer service and optionally apply it. Can extract title, artist (author), album (series), genre, year, cover, etc. Supports batch processing of multiple books.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'book_ids' => [
                        'type' => 'array',
                        'items' => ['type' => 'integer'],
                        'description' => 'Specific book IDs to process',
                    ],
                    'series_id' => [
                        'type' => 'integer',
                        'description' => 'Process all books in a series',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'Maximum books to process (default: 50)',
                    ],
                    'auto_apply' => [
                        'type' => 'boolean',
                        'description' => 'Apply extracted metadata to database (default: false - preview only)',
                    ],
                ],
            ],
        ];
    }

    protected static function mergeAuthors(): array
    {
        return [
            'name' => 'merge_authors',
            'description' => 'Merge multiple authors into a single primary author. All books from secondary authors will be reassigned to the primary author. The secondary authors will be deleted. Optionally move the book files to directories named after the primary author.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'primary_author_name' => [
                        'type' => 'string',
                        'description' => 'Name of the author to keep (the target/primary author)',
                    ],
                    'secondary_author_names' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Names of authors to merge into the primary author (will be deleted)',
                    ],
                    'move_files' => [
                        'type' => 'boolean',
                        'description' => 'Move book files to directories named after the primary author (default: false)',
                    ],
                    'preview_only' => [
                        'type' => 'boolean',
                        'description' => 'Show preview without applying changes (default: true)',
                    ],
                ],
                'required' => ['primary_author_name', 'secondary_author_names'],
            ],
        ];
    }

    protected static function mergeSeries(): array
    {
        return [
            'name' => 'merge_series',
            'description' => 'Merge multiple series into a single primary series. All books from secondary series will be reassigned to the primary series, preserving their series numbers. The secondary series will be deleted. Optionally move the book files to directories named after the primary series.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'primary_series_name' => [
                        'type' => 'string',
                        'description' => 'Name of the series to keep (the target/primary series)',
                    ],
                    'secondary_series_names' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Names of series to merge into the primary series (will be deleted)',
                    ],
                    'move_files' => [
                        'type' => 'boolean',
                        'description' => 'Move book files to directories named after the primary series (default: false)',
                    ],
                    'preview_only' => [
                        'type' => 'boolean',
                        'description' => 'Show preview without applying changes (default: true)',
                    ],
                ],
                'required' => ['primary_series_name', 'secondary_series_names'],
            ],
        ];
    }
}
