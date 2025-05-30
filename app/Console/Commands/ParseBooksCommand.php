<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\BookDirectoryParser;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Helper\ProgressBar;

class ParseBooksCommand extends Command
{
    protected $signature = 'books:parse 
        {path? : Path to the books directory (default: storage/app/books)}
        {--output= : Output file path (default: storage/app/books_metadata.json)}
        {--format=json : Output format (json, csv, table, sql)}
        {--limit=0 : Maximum number of books to process (0 for no limit)}
        {--no-progress : Disable progress bar}
        {--extensions=mp3,m4b,m4a,mp4,ogg,flac : Comma-separated list of file extensions to include}
        {--min-size=100 : Minimum file size in KB}
        {--max-depth=10 : Maximum directory depth to scan}
        {--dry-run : Parse files but don\'t save output}
        {--verbose : Show detailed output}';

    protected $description = 'Parse book directories and extract metadata';

    protected $parser;

    public function __construct(BookDirectoryParser $parser)
    {
        parent::__construct();
        $this->parser = $parser;
    }

    public function handle()
    {
        $path = $this->getPath();
        $outputFile = $this->getOutputPath();
        $format = strtolower($this->option('format'));
        $limit = (int)$this->option('limit');
        $showProgress = !$this->option('no-progress');
        $verbose = $this->option('verbose');
        
        $options = [
            'extensions' => array_map('trim', explode(',', $this->option('extensions'))),
            'min_file_size' => (int)$this->option('min-size') * 1024,
            'max_depth' => (int)$this->option('max-depth'),
        ];

        $this->info("Starting book directory scan...");
        $this->line("Path: $path");
        $this->line("File extensions: " . implode(', ', $options['extensions']));
        $this->line("Minimum file size: " . $this->formatBytes($options['min_file_size']));
        
        if ($verbose) {
            $this->info("Verbose mode enabled");
        }

        try {
            $startTime = microtime(true);
            
            // Parse the directory
            $books = $this->parser->parseDirectory($path, $options);
            
            // Apply limit if specified
            if ($limit > 0) {
                $books = array_slice($books, 0, $limit);
            }
            
            $elapsed = microtime(true) - $startTime;
            
            $this->newLine();
            $this->info(sprintf(
                'Found %d books in %.2f seconds', 
                count($books), 
                $elapsed
            ));
            
            // Show sample output
            if (count($books) > 0) {
                $this->displaySampleOutput($books, $format);
                
                // Save output if not a dry run
                if (!$this->option('dry-run')) {
                    $this->saveOutput($books, $outputFile, $format);
                    $this->info("Results saved to: $outputFile");
                } else {
                    $this->warn('Dry run - output not saved');
                }
            } else {
                $this->warn('No books found matching the criteria');
            }
            
            return 0;
            
        } catch (\Exception $e) {
            $this->error('Error: ' . $e->getMessage());
            if ($verbose) {
                $this->error($e->getTraceAsString());
            }
            return 1;
        }
    }
    
    protected function getPath(): string
    {
        $path = $this->argument('path') ?: storage_path('app/books');
        return rtrim($path, '/\\');
    }
    
    protected function getOutputPath(): string
    {
        if ($this->option('output')) {
            return $this->option('output');
        }
        
        $format = strtolower($this->option('format'));
        $ext = $format === 'csv' ? 'csv' : 'json';
        return storage_path("app/books_metadata.$ext");
    }
    
    protected function displaySampleOutput(array $books, string $format): void
    {
        $sample = array_slice($books, 0, min(5, count($books)));
        
        $this->newLine();
        $this->info('Sample Output:');
        
        if ($format === 'table') {
            $this->displayAsTable($sample);
        } elseif ($format === 'csv') {
            $this->displayAsCsv($sample);
        } else {
            $this->displayAsJson($sample);
        }
    }
    
    protected function displayAsTable(array $books): void
    {
        $headers = ['Title', 'Author', 'Series', '#', 'Year', 'Narrator'];
        $rows = [];
        
        foreach ($books as $book) {
            $rows[] = [
                $book['title'] ?? '-',
                $book['author'] ?? '-',
                $book['series'] ?? '-',
                $book['series_number'] ?? '-',
                $book['year'] ?? '-',
                $book['narrator'] ?? '-',
            ];
        }
        
        $this->table($headers, $rows);
    }
    
    protected function displayAsCsv(array $books): void
    {
        if (empty($books)) {
            return;
        }
        
        // Get headers from the first book
        $headers = array_keys($books[0]);
        
        // Output headers
        $this->line(implode(',', $headers));
        
        // Output rows
        foreach ($books as $book) {
            $row = [];
            foreach ($headers as $header) {
                $value = $book[$header] ?? '';
                // Escape quotes and wrap in quotes if contains comma or quote
                if (is_string($value) && (str_contains($value, ',') || str_contains($value, '"'))) {
                    $value = '"' . str_replace('"', '""', $value) . '"';
                }
                $row[] = $value;
            }
            $this->line(implode(',', $row));
        }
    }
    
    protected function displayAsJson(array $books): void
    {
        $this->line(json_encode($books, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
    
    protected function saveOutput(array $books, string $outputFile, string $format): void
    {
        $dir = dirname($outputFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        if ($format === 'csv') {
            $this->saveAsCsv($books, $outputFile);
        } else {
            file_put_contents($outputFile, json_encode($books, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
    }
    
    protected function saveAsCsv(array $books, string $outputFile): void
    {
        if (empty($books)) {
            file_put_contents($outputFile, '');
            return;
        }
        
        $fp = fopen($outputFile, 'w');
        
        // Write headers
        fputcsv($fp, array_keys($books[0]));
        
        // Write rows
        foreach ($books as $book) {
            fputcsv($fp, $book);
        }
        
        fclose($fp);
    }
    
    protected function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));
        
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}
