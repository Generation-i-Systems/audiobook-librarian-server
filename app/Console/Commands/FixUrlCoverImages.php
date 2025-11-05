<?php

namespace App\Console\Commands;

use App\Contracts\DocumentStoreServiceInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FixUrlCoverImages extends Command
{
    protected $signature = 'books:fix-url-covers
                            {--dry-run : Show what would be changed without making changes}
                            {--limit= : Maximum number of books to process}';

    protected $description = 'Download cover images for books that have URLs instead of local files';

    private DocumentStoreServiceInterface $documentStore;

    public function __construct(DocumentStoreServiceInterface $documentStore)
    {
        parent::__construct();
        $this->documentStore = $documentStore;
    }

    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;

        $this->info('Scanning books for URL cover images...');
        if ($isDryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        $bookRoot = rtrim(config('app.book_root', '/media/lyra_data1/audiobooks/books'), '/');

        $page = 1;
        $perPage = 100;
        $totalProcessed = 0;
        $totalFixed = 0;
        $totalFailed = 0;
        $totalFound = 0;

        while (true) {
            $result = $this->documentStore->listBooks($page, $perPage, [], false);
            $books = $result['data'];

            if (empty($books)) {
                break;
            }

            foreach ($books as $book) {
                if ($limit && $totalProcessed >= $limit) {
                    break 2;
                }

                $totalProcessed++;

                $bookId = $book['id'] ?? $book['documentId'] ?? null;
                $coverImage = $book['coverImage'] ?? $book['cover_image'] ?? null;
                $coverUrl = $book['cover_url'] ?? null;

                if (!$bookId) {
                    continue;
                }

                // Check if coverImage is an external URL that needs to be downloaded
                // Ignore internal API URLs (books.saturn.generation-i.com)
                $imageUrl = null;

                if ($coverImage && is_string($coverImage) && (str_starts_with($coverImage, 'http://') || str_starts_with($coverImage, 'https://'))) {
                    // Skip internal API URLs
                    if (!str_contains($coverImage, 'books.saturn.generation-i.com')) {
                        $imageUrl = $coverImage;
                    }
                }

                if (!$imageUrl) {
                    continue;
                }

                $totalFound++;

                // Get full book details to get directoryPath
                $fullBook = $this->documentStore->getBook($bookId);
                $directoryPath = $fullBook['directoryPath'] ?? $fullBook['directory_path'] ?? null;

                if (!$directoryPath) {
                    $this->error("Book ID {$bookId}: No directory path found, skipping");
                    $totalFailed++;
                    continue;
                }

                $this->line('');
                $this->info("Book ID: {$bookId}");
                $this->line("  Title: " . ($book['title'] ?? 'Unknown'));
                $this->line("  Cover URL: " . substr($imageUrl, 0, 80) . (strlen($imageUrl) > 80 ? '...' : ''));
                $this->line("  Directory: " . $directoryPath);

                if (!$isDryRun) {
                    $result = $this->downloadAndUpdateCover($bookId, $imageUrl, $directoryPath, $bookRoot);
                    if ($result['success']) {
                        $totalFixed++;
                        $this->comment("  ✓ Downloaded: {$result['path']}");
                    } else {
                        $totalFailed++;
                        $this->error("  ✗ Failed: {$result['error']}");
                    }

                    // Add a small delay to avoid rate limiting
                    usleep(500000); // 0.5 seconds
                } else {
                    $this->comment("  Would download to: {$directoryPath}/");
                }
            }

            $page++;
        }

        $this->newLine();
        $this->info("Processed {$totalProcessed} books");
        $this->info("Found {$totalFound} books with URL covers");

        if (!$isDryRun) {
            $this->info("Successfully fixed: {$totalFixed}");
            if ($totalFailed > 0) {
                $this->warn("Failed to fix: {$totalFailed}");
            }
        } else {
            $this->warn('DRY RUN MODE - No changes were made');
            $this->info('Run without --dry-run to apply changes');
        }

        return 0;
    }

    private function downloadAndUpdateCover(string $bookId, string $imageUrl, string $directoryPath, string $bookRoot): array
    {
        $result = [
            'success' => false,
            'path' => null,
            'error' => null,
        ];

        try {
            // Determine source from URL
            $source = 'unknown';
            if (str_contains($imageUrl, 'amazon.com') || str_contains($imageUrl, 'audible.com')) {
                $source = 'audible';
            } elseif (str_contains($imageUrl, 'google.com') || str_contains($imageUrl, 'googleapis.com')) {
                $source = 'googlebooks';
            }

            // Download the image using Laravel HTTP client
            $response = Http::withOptions([
                'verify' => false,
            ])
                ->timeout(30)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                ])
                ->get($imageUrl);

            if (!$response->successful()) {
                $result['error'] = 'Failed to download image from URL (HTTP ' . $response->status() . ')';
                return $result;
            }

            $imageData = $response->body();

            // Determine extension
            $extension = $this->getImageExtensionFromUrl($imageUrl);
            $filename = "cover_{$source}.{$extension}";

            // Build full path - check if directoryPath is already absolute
            if (str_starts_with($directoryPath, '/')) {
                // Already an absolute path
                $fullDirectoryPath = $directoryPath;
            } else {
                // Relative path, prepend bookRoot
                $fullDirectoryPath = $bookRoot . '/' . ltrim($directoryPath, '/');
            }
            $fullFilePath = $fullDirectoryPath . '/' . $filename;

            // Ensure directory exists
            if (!is_dir($fullDirectoryPath)) {
                $result['error'] = 'Directory does not exist: ' . $fullDirectoryPath;
                return $result;
            }

            // Write the file
            if (!file_put_contents($fullFilePath, $imageData)) {
                $result['error'] = 'Failed to write file: ' . $fullFilePath;
                return $result;
            }

            // Set permissions
            chmod($fullFilePath, 0664);

            // Update database with relative path (just the filename)
            $this->documentStore->updateBook($bookId, ['cover_image' => $filename]);

            $result['success'] = true;
            $result['path'] = $filename;

            Log::info('Downloaded cover image for book', [
                'book_id' => $bookId,
                'url' => $imageUrl,
                'path' => $filename,
            ]);
        } catch (\Exception $e) {
            $result['error'] = $e->getMessage();
            Log::error('Failed to download cover image', [
                'book_id' => $bookId,
                'url' => $imageUrl,
                'error' => $e->getMessage(),
            ]);
        }

        return $result;
    }

    private function getImageExtensionFromUrl(string $url): string
    {
        $extension = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION);

        if (in_array(strtolower($extension), ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
            return strtolower($extension);
        }

        return 'jpg';
    }
}
