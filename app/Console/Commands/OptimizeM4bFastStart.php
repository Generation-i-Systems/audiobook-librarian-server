<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Contracts\DocumentStoreServiceInterface;
use Illuminate\Support\Facades\Storage;

class OptimizeM4bFastStart extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:optimize-m4b-faststart {--book= : Optimize a specific book ID} {--dry-run : Check without modifying files}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scan and optimize M4B/M4A audiobooks to use fast-start layout (moov at the beginning) for progressive playback.';

    private DocumentStoreServiceInterface $documentStoreService;

    public function __construct(DocumentStoreServiceInterface $documentStoreService)
    {
        parent::__construct();
        $this->documentStoreService = $documentStoreService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $bookId = $this->option('book');
        $dryRun = $this->option('dry-run');

        if ($bookId) {
            $book = $this->documentStoreService->getBook($bookId);
            if (!$book) {
                $this->error("Book ID {$bookId} not found.");
                return 1;
            }
            $books = [$book];
        } else {
            $books = $this->documentStoreService->getAllBooks();
        }

        $this->info("Scanning " . count($books) . " books...");

        $optimizedCount = 0;
        $staleCount = 0;
        $totalFilesChecked = 0;

        foreach ($books as $book) {
            $directoryPath = $book['directoryPath'] ?? null;
            if (!$directoryPath || !Storage::disk('books')->exists($directoryPath)) {
                continue;
            }

            $files = Storage::disk('books')->allFiles($directoryPath);
            foreach ($files as $file) {
                $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                if (!in_array($extension, ['m4b', 'm4a', 'mp4'])) {
                    continue;
                }

                $totalFilesChecked++;
                $fullPath = Storage::disk('books')->path($file);

                $analysis = $this->analyzeMp4File($fullPath);
                if ($analysis['is_fast_start']) {
                    $this->line("  [OK] {$file} is already fast-start friendly.");
                    continue;
                }

                $staleCount++;
                $this->warn("  [LAG] {$file} is NOT fast-start friendly (moov at offset: " . ($analysis['moov_offset'] ?? 'unknown') . ").");

                if ($dryRun) {
                    continue;
                }

                $this->info("  Optimizing {$file} using ffmpeg...");
                $tempFile = $fullPath . '.tmp_faststart';

                // Run ffmpeg to rearrange atoms
                $cmd = sprintf(
                    'ffmpeg -y -i %s -c copy -map_metadata 0 -movflags +faststart %s 2>&1',
                    escapeshellarg($fullPath),
                    escapeshellarg($tempFile)
                );

                exec($cmd, $output, $returnCode);

                if ($returnCode === 0 && is_file($tempFile) && filesize($tempFile) > 0) {
                    // Replace original with optimized
                    unlink($fullPath);
                    rename($tempFile, $fullPath);
                    $this->info("  [SUCCESS] Optimized {$file} successfully.");
                    $optimizedCount++;
                } else {
                    $this->error("  [FAILED] Failed to optimize {$file}. Command output:");
                    $this->error(implode("\n", $output));
                    if (is_file($tempFile)) {
                        unlink($tempFile);
                    }
                }
            }
        }

        $this->info("Scan complete!");
        $this->info("Total files checked: {$totalFilesChecked}");
        $this->info("Not fast-start friendly: {$staleCount}");
        if (!$dryRun) {
            $this->info("Successfully optimized: {$optimizedCount}");
        }

        return 0;
    }

    private function analyzeMp4File(string $filePath): array
    {
        if (!is_file($filePath)) {
            return ['is_fast_start' => false, 'moov_offset' => null, 'moov_size' => null];
        }

        $handle = fopen($filePath, 'rb');
        if (!$handle) {
            return ['is_fast_start' => false, 'moov_offset' => null, 'moov_size' => null];
        }

        $fileSize = filesize($filePath);
        $offset = 0;
        $moovOffset = null;
        $moovSize = null;
        $mdatOffset = null;

        while ($offset < $fileSize) {
            if (fseek($handle, $offset) !== 0) {
                break;
            }

            $header = fread($handle, 8);
            if (strlen($header) < 8) {
                break;
            }

            $size = unpack('N', substr($header, 0, 4))[1];
            $type = substr($header, 4, 4);

            $boxSize = $size;

            if ($size === 1) {
                $extHeader = fread($handle, 8);
                if (strlen($extHeader) < 8) {
                    break;
                }
                $boxSize = unpack('J', $extHeader)[1];
            }

            if ($type === 'moov') {
                $moovOffset = $offset;
                $moovSize = $boxSize;
            } elseif ($type === 'mdat') {
                $mdatOffset = $offset;
            }

            if ($boxSize <= 0) {
                break;
            }

            $offset += $boxSize;
        }

        fclose($handle);

        $isFastStart = false;
        if ($moovOffset !== null && $mdatOffset !== null) {
            $isFastStart = $moovOffset < $mdatOffset;
        }

        return [
            'is_fast_start' => $isFastStart,
            'moov_offset' => $moovOffset,
            'moov_size' => $moovSize,
        ];
    }
}
