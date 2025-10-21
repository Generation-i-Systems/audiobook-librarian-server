<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class FixUrlCoverImagesDirect extends Command
{
    protected $signature = 'books:fix-url-covers-direct
                            {--dry-run : Show what would be changed without making changes}
                            {--limit= : Maximum number of books to process}';

    protected $description = 'Fix books with URL covers by downloading images (direct MySQL access)';

    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;

        $this->info('Scanning books for URL cover images (direct MySQL)...');
        if ($isDryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        $bookRoot = rtrim(config('app.book_root', '/media/lyra_data1/audiobooks/books'), '/');

        // Get books with URL covers directly from MySQL
        $query = DB::table('books')
            ->where('cover_image', 'like', 'http%')
            ->whereNotNull('directory_path');

        if ($limit) {
            $query->limit($limit);
        }

        $books = $query->get(['id', 'title', 'cover_image', 'directory_path']);

        $totalFound = count($books);
        $totalFixed = 0;
        $totalFailed = 0;

        $this->info("Found {$totalFound} books with URL covers");
        $this->newLine();

        foreach ($books as $book) {
            $this->line('');
            $this->info("Book ID: {$book->id}");
            $this->line("  Title: {$book->title}");
            $this->line("  Cover URL: " . substr($book->cover_image, 0, 80) . (strlen($book->cover_image) > 80 ? '...' : ''));
            $this->line("  Directory: {$book->directory_path}");

            if (!$isDryRun) {
                $result = $this->downloadAndUpdateCover($book, $bookRoot);
                if ($result['success']) {
                    $totalFixed++;
                    $this->comment("  ✓ Downloaded: {$result['path']}");
                } else {
                    $totalFailed++;
                    $this->error("  ✗ Failed: {$result['error']}");
                }

                // Add delay to avoid rate limiting
                usleep(500000); // 0.5 seconds
            } else {
                $this->comment("  Would download to: {$book->directory_path}/");
            }
        }

        $this->newLine();
        $this->info("Found {$totalFound} books with URL covers");

        if (!$isDryRun) {
            $this->info("Successfully fixed: {$totalFixed}");
            $this->info("Failed to fix: {$totalFailed}");
        } else {
            $this->warn('DRY RUN MODE - No changes were made');
            $this->info('Run without --dry-run to apply changes');
        }

        return 0;
    }

    protected function downloadAndUpdateCover(object $book, string $bookRoot): array
    {
        $result = ['success' => false, 'error' => null, 'path' => null];

        try {
            // Determine source from URL
            $source = 'unknown';
            if (str_contains($book->cover_image, 'amazon.com') || str_contains($book->cover_image, 'audible.com')) {
                $source = 'audible';
            } elseif (str_contains($book->cover_image, 'google.com') || str_contains($book->cover_image, 'googleapis.com')) {
                $source = 'googlebooks';
            }

            // Download the image using curl
            $ch = curl_init($book->cover_image);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
            $imageData = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if (!$imageData || $httpCode !== 200) {
                $result['error'] = 'Failed to download image from URL (HTTP ' . $httpCode . ')';
                return $result;
            }

            // Determine extension
            $extension = $this->getImageExtensionFromUrl($book->cover_image);
            $filename = "cover_{$source}.{$extension}";

            // Build full path - handle both absolute and relative paths
            if (str_starts_with($book->directory_path, '/')) {
                $fullDirectoryPath = $book->directory_path;
            } else {
                $fullDirectoryPath = $bookRoot . '/' . ltrim($book->directory_path, '/');
            }
            $fullFilePath = $fullDirectoryPath . '/' . $filename;

            // Ensure directory exists
            if (!is_dir($fullDirectoryPath)) {
                $result['error'] = 'Directory does not exist: ' . $fullDirectoryPath;
                return $result;
            }

            // Write the file
            if (!file_put_contents($fullFilePath, $imageData)) {
                $result['error'] = 'Failed to write file';
                return $result;
            }

            chmod($fullFilePath, 0664);

            // Update database with relative path
            $relativeCoverPath = ltrim($book->directory_path, '/') . '/' . $filename;
            DB::table('books')
                ->where('id', $book->id)
                ->update(['cover_image' => $relativeCoverPath]);

            $result['success'] = true;
            $result['path'] = $filename;
        } catch (\Exception $e) {
            $result['error'] = $e->getMessage();
        }

        return $result;
    }

    protected function getImageExtensionFromUrl(string $url): string
    {
        // Try to get extension from URL
        $path = parse_url($url, PHP_URL_PATH);
        if ($path) {
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                return $ext;
            }
        }

        // Default to jpg
        return 'jpg';
    }
}
