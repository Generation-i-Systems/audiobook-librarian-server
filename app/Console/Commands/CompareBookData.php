<?php

namespace App\Console\Commands;

use App\Contracts\DocumentStoreServiceInterface;
use App\Models\Book;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CompareBookData extends Command
{
    protected $signature = 'app:compare-book-data {title?} 
        {--id=} 
        {--list : List all books in MySQL} 
        {--all : Compare all fields including JSON data}';
    protected $description = 'Compare book data between MongoDB and MySQL';

    /** @var array */
    protected $fieldsToCompare = [
        'title', 'subtitle', 'description', 'published_date', 'publisher',
        'language', 'book_number', 'duration', 'runtime', 'audio_file_count',
        'needs_review', 'needs_review_reasons', 'source', 'path', 'directory_path',
    ];

    /** @var array */
    protected $jsonFields = [
        'mongo_record', 'file_tags', 'audible_info', 'google_books_info', 'hardcover_info', 'audiobook_bay_info',
    ];

    public function __construct(
        private DocumentStoreServiceInterface $mongoService
    ) {
        parent::__construct();
    }

    public function handle()
    {
        // Handle list option
        if ($this->option('list')) {
            $books = Book::select('id', 'title', 'mongo_id', 'created_at')
                ->orderBy('id', 'desc')
                ->limit(50)
                ->get();
            $this->table(['ID', 'Title', 'MongoDB ID', 'Created At'], $books->toArray());
            return 0;
        }

        // Get the book from MySQL
        $mysqlBook = null;

        // Handle search by MongoDB ID if provided
        if ($mongoId = $this->option('id')) {
            $mysqlBook = Book::where('mongo_id', $mongoId)->first();
            if (!$mysqlBook) {
                $this->error("No book found with MongoDB ID: {$mongoId}");
                return 1;
            }
        } elseif ($title = $this->argument('title')) {
            // Handle search by title
            $mysqlBook = Book::where('title', 'LIKE', "%{$title}%")->first();
            if (!$mysqlBook) {
                $this->error("No book found with title containing: {$title}");
                return 1;
            }
        } else {
            $this->error("Please provide either a title or --id option");
            return 1;
        }

        // Get the corresponding MongoDB record
        $mongoBook = $this->mongoService->getBook($mysqlBook->mongo_id);

        if (!$mongoBook) {
            $this->error("No MongoDB record found with ID: {$mysqlBook->mongo_id}");
            return 1;
        }

        // Display comparison
        $this->compareBookData($mongoBook, $mysqlBook);

        return 0;
    }

    protected function compareBookData($mongoBook, $mysqlBook)
    {
        $this->info("\n=== Book Comparison ===");
        $this->info("Title: " . ($mysqlBook->title ?? 'N/A'));
        $this->info("MongoDB ID: " . $mysqlBook->mongo_id);
        $this->info("MySQL ID: " . $mysqlBook->id);

        $this->info("\n=== Field Comparison ===");
        $comparison = [];

        // Compare standard fields
        foreach ($this->fieldsToCompare as $field) {
            $mongoValue = $mongoBook[$field] ?? $mongoBook[Str::camel($field)] ?? null;
            $mysqlValue = $mysqlBook->$field;

            // Handle different date formats
            if (in_array($field, ['created_at', 'updated_at', 'published_date'])) {
                $mongoValue = $mongoValue ? date('Y-m-d H:i:s', strtotime($mongoValue)) : null;
                $mysqlValue = $mysqlValue ? (string) $mysqlValue : null;
            }

            $comparison[] = [
                'Field' => $field,
                'MongoDB' => $this->formatValue($mongoValue),
                'MySQL' => $this->formatValue($mysqlValue),
                'Match' => $this->valuesMatch($mongoValue, $mysqlValue) ? '✓' : '✗',
            ];
        }

        // Compare JSON fields if requested
        if ($this->option('all')) {
            foreach ($this->jsonFields as $field) {
                $mongoValue = $mongoBook[$field] ?? null;
                $mysqlValue = $mysqlBook->$field;

                // For JSON fields, try to decode them for better comparison
                $mongoJson = $this->tryJsonDecode($mongoValue);
                $mysqlJson = $this->tryJsonDecode($mysqlValue);

                $comparison[] = [
                    'Field' => $field,
                    'MongoDB' => $mongoJson !== null ? '[JSON]' : $this->formatValue($mongoValue),
                    'MySQL' => $mysqlJson !== null ? '[JSON]' : $this->formatValue($mysqlValue),
                    'Match' => $this->valuesMatch($mongoValue, $mysqlValue) ? '✓' : '✗',
                ];
            }
        }

        $this->table(['Field', 'MongoDB', 'MySQL', 'Match'], $comparison);

        // Show relationship counts
        $this->info("\n=== Relationship Counts ===");
        $this->info(sprintf("Authors: %d", $mysqlBook->authors()->count()));
        $this->info(sprintf("Narrators: %d", $mysqlBook->narrators()->count()));
        $this->info(sprintf("Genres: %d", $mysqlBook->genres()->count()));
        $this->info(sprintf("Series: %d", $mysqlBook->series()->count()));
    }
    
    protected function formatValue($value)
    {
        if (is_null($value)) {
            return 'NULL';
        }
        
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        
        if (is_array($value) || is_object($value)) {
            $json = json_encode($value, JSON_PRETTY_PRINT);
            return strlen($json) > 100 ? substr($json, 0, 100) . '...' : $json;
        }
        
        $stringValue = (string)$value;
        return strlen($stringValue) > 100 ? substr($stringValue, 0, 100) . '...' : $stringValue;
    }
    
    protected function valuesMatch($value1, $value2)
    {
        // Handle null cases
        if (is_null($value1) && is_null($value2)) {
            return true;
        }
        
        if (is_null($value1) || is_null($value2)) {
            return false;
        }
        
        // Compare JSON strings
        $json1 = $this->tryJsonDecode($value1);
        $json2 = $this->tryJsonDecode($value2);
        
        if ($json1 !== null || $json2 !== null) {
            return $json1 == $json2;
        }
        
        // Simple string comparison
        return (string)$value1 === (string)$value2;
    }
    
    protected function tryJsonDecode($value)
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
        }
        return is_array($value) || is_object($value) ? $value : null;
    }
}
