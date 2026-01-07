#!/usr/bin/env php
<?php

/**
 * Script to replace MP3 files with M4A files in Deathlands audiobook collection
 *
 * Features:
 * - Compares file sizes before replacement
 * - Moves MP3 files to trash instead of deleting
 * - Tracks and reports storage savings
 * - Flags directories with multiple MP3 files
 * - Supports dry-run mode
 */

class AudiobookReplacer
{
    private string $mp3BaseDir;
    private string $m4aSourceDir;
    private string $trashDir;
    private bool $dryRun;
    private bool $includeMultiMp3;
    private array $stats = [
        'total_replacements' => 0,
        'total_skipped_larger_m4a' => 0,
        'total_skipped_no_match' => 0,
        'total_multi_mp3_dirs' => 0,
        'total_bytes_saved' => 0,
        'replacements' => [],
        'skipped' => [],
        'multi_mp3_dirs' => [],
        'errors' => [],
    ];

    public function __construct(
        string $mp3BaseDir,
        string $m4aSourceDir,
        string $trashDir,
        bool $dryRun = false,
        bool $includeMultiMp3 = false
    ) {
        $this->mp3BaseDir = rtrim($mp3BaseDir, '/');
        $this->m4aSourceDir = rtrim($m4aSourceDir, '/');
        $this->trashDir = rtrim($trashDir, '/');
        $this->dryRun = $dryRun;
        $this->includeMultiMp3 = $includeMultiMp3;
    }

    public function run(): void
    {
        echo "Audiobook MP3 to M4A Replacement Script\n";
        echo "========================================\n\n";

        if ($this->dryRun) {
            echo "*** DRY RUN MODE - No changes will be made ***\n\n";
        }

        echo "MP3 Directory: {$this->mp3BaseDir}\n";
        echo "M4A Directory: {$this->m4aSourceDir}\n";
        echo "Trash Directory: {$this->trashDir}\n";
        echo "Include Multi-MP3 Dirs: " . ($this->includeMultiMp3 ? 'YES' : 'NO') . "\n\n";

        // Scan for M4A files
        $m4aFiles = $this->scanM4aFiles();
        echo "Found " . count($m4aFiles) . " M4A files\n\n";

        // Scan for MP3 directories
        $mp3Dirs = $this->scanMp3Directories();
        echo "Found " . count($mp3Dirs) . " MP3 directories\n\n";

        // Process replacements
        $this->processReplacements($m4aFiles, $mp3Dirs);

        // Display report
        $this->displayReport();
    }

    private function scanM4aFiles(): array
    {
        $files = [];
        $pattern = $this->m4aSourceDir . '/*.m4a';

        foreach (glob($pattern) as $filepath) {
            // Extract book number from filename
            // Expected format: "James Axler - Deathlands 080 - Sunspot.m4a"
            if (preg_match('/Deathlands\s+(\d+)\s*-/', basename($filepath), $matches)) {
                $bookNum = (int)$matches[1];
                $files[$bookNum] = [
                    'path' => $filepath,
                    'size' => filesize($filepath),
                    'basename' => basename($filepath),
                ];
            }
        }

        return $files;
    }

    private function scanMp3Directories(): array
    {
        $dirs = [];
        $iterator = new DirectoryIterator($this->mp3BaseDir);

        foreach ($iterator as $item) {
            if ($item->isDot() || !$item->isDir()) {
                continue;
            }

            $dirName = $item->getFilename();

            // Extract book number from directory name
            // Expected format: "075 Shatter Zone" or "075 Shatter Zone (The Coldfire Project #1)"
            if (preg_match('/^(\d+)\s+/', $dirName, $matches)) {
                $bookNum = (int)$matches[1];
                $dirPath = $item->getPathname();

                // Find all MP3 files in this directory
                $mp3Files = glob($dirPath . '/*.mp3');

                $dirs[$bookNum] = [
                    'path' => $dirPath,
                    'name' => $dirName,
                    'mp3_files' => $mp3Files,
                    'mp3_count' => count($mp3Files),
                ];

                // Flag directories with multiple MP3 files
                if (count($mp3Files) > 1) {
                    $this->stats['multi_mp3_dirs'][] = [
                        'book_num' => $bookNum,
                        'dir_name' => $dirName,
                        'mp3_count' => count($mp3Files),
                    ];
                    $this->stats['total_multi_mp3_dirs']++;
                }
            }
        }

        return $dirs;
    }

    private function processReplacements(array $m4aFiles, array $mp3Dirs): void
    {
        echo "Processing replacements...\n";
        echo str_repeat('-', 80) . "\n";

        foreach ($m4aFiles as $bookNum => $m4aInfo) {
            if (!isset($mp3Dirs[$bookNum])) {
                $this->stats['skipped'][] = [
                    'book_num' => $bookNum,
                    'reason' => 'No matching MP3 directory',
                    'm4a_file' => $m4aInfo['basename'],
                ];
                $this->stats['total_skipped_no_match']++;
                echo "Book {$bookNum}: No matching MP3 directory - SKIPPED\n";
                continue;
            }

            $mp3Dir = $mp3Dirs[$bookNum];

            if ($mp3Dir['mp3_count'] === 0) {
                echo "Book {$bookNum}: No MP3 files found - SKIPPED\n";
                continue;
            }

            // Handle multiple MP3 files
            if ($mp3Dir['mp3_count'] > 1) {
                if (!$this->includeMultiMp3) {
                    echo "Book {$bookNum}: Multiple MP3 files ({$mp3Dir['mp3_count']}) - SKIPPED (flagged for review)\n";
                    continue;
                }

                $totalMp3Size = 0;
                foreach ($mp3Dir['mp3_files'] as $mp3) {
                    $totalMp3Size += filesize($mp3);
                }
                $mp3Size = $totalMp3Size;
            } else {
                $mp3Size = filesize($mp3Dir['mp3_files'][0]);
            }

            $m4aSize = $m4aInfo['size'];

            // Compare sizes
            if ($m4aSize >= $mp3Size) {
                $this->stats['skipped'][] = [
                    'book_num' => $bookNum,
                    'reason' => 'M4A is larger or equal',
                    'mp3_file' => basename($mp3File),
                    'mp3_size' => $mp3Size,
                    'm4a_file' => $m4aInfo['basename'],
                    'm4a_size' => $m4aSize,
                ];
                $this->stats['total_skipped_larger_m4a']++;
                echo sprintf(
                    "Book %03d: M4A (%s) >= MP3 (%s) - SKIPPED\n",
                    $bookNum,
                    $this->formatBytes($m4aSize),
                    $this->formatBytes($mp3Size)
                );
                continue;
            }

            // Perform replacement
            $savings = $mp3Size - $m4aSize;
            $this->stats['total_bytes_saved'] += $savings;

            echo sprintf(
                "Book %03d: %s -> %s (saves %s)\n",
                $bookNum,
                $this->formatBytes($mp3Size),
                $this->formatBytes($m4aSize),
                $this->formatBytes($savings)
            );

            if (!$this->dryRun) {
                try {
                    if ($mp3Dir['mp3_count'] > 1) {
                        $this->replaceMultipleFiles($mp3Dir['mp3_files'], $m4aInfo['path'], $mp3Dir['path']);
                    } else {
                        $this->replaceFile($mp3Dir['mp3_files'][0], $m4aInfo['path'], $mp3Dir['path']);
                    }
                    $this->stats['total_replacements']++;
                    $this->stats['replacements'][] = [
                        'book_num' => $bookNum,
                        'mp3_files' => array_map('basename', $mp3Dir['mp3_files']),
                        'mp3_count' => $mp3Dir['mp3_count'],
                        'mp3_size' => $mp3Size,
                        'm4a_file' => $m4aInfo['basename'],
                        'm4a_size' => $m4aSize,
                        'savings' => $savings,
                    ];
                    echo "  -> Replacement completed successfully\n";
                } catch (Exception $e) {
                    $this->stats['errors'][] = [
                        'book_num' => $bookNum,
                        'error' => $e->getMessage(),
                    ];
                    echo "  -> ERROR: " . $e->getMessage() . "\n";
                }
            } else {
                $this->stats['total_replacements']++;
                $this->stats['replacements'][] = [
                    'book_num' => $bookNum,
                    'mp3_files' => array_map('basename', $mp3Dir['mp3_files']),
                    'mp3_count' => $mp3Dir['mp3_count'],
                    'mp3_size' => $mp3Size,
                    'm4a_file' => $m4aInfo['basename'],
                    'm4a_size' => $m4aSize,
                    'savings' => $savings,
                ];
            }
        }
    }

    private function replaceFile(string $mp3File, string $m4aFile, string $targetDir): void
    {
        $this->replaceMultipleFiles([$mp3File], $m4aFile, $targetDir);
    }

    private function replaceMultipleFiles(array $mp3Files, string $m4aFile, string $targetDir): void
    {
        $timestamp = date('Y-m-d_His');
        preg_match('/(\d+)/', basename($targetDir), $matches);
        $bookNum = $matches[1] ?? 'unknown';
        $trashItemId = $timestamp . '_audiobook_' . $bookNum;

        $trashItemPath = $this->trashDir . '/' . $trashItemId;
        $trashFilesPath = $trashItemPath . '/files';

        if (!is_dir($trashFilesPath)) {
            mkdir($trashFilesPath, 0775, true);
            chown($trashItemPath, 'eric');
            chgrp($trashItemPath, 'audio');
            chmod($trashItemPath, 0775);
            chown($trashFilesPath, 'eric');
            chgrp($trashFilesPath, 'audio');
            chmod($trashFilesPath, 0775);
        }

        $newM4aPath = $targetDir . '/' . basename($m4aFile);
        if (!copy($m4aFile, $newM4aPath)) {
            throw new Exception("Failed to copy M4A file to target directory");
        }

        $movedMp3Files = [];
        $totalMp3Size = 0;

        foreach ($mp3Files as $mp3File) {
            $mp3Basename = basename($mp3File);
            $trashMp3Path = $trashFilesPath . '/' . $mp3Basename;

            if (!rename($mp3File, $trashMp3Path)) {
                foreach ($movedMp3Files as $movedFile) {
                    @rename($movedFile['trash_path'], $movedFile['original_path']);
                }
                unlink($newM4aPath);
                throw new Exception("Failed to move MP3 file to trash: {$mp3Basename}");
            }

            chown($trashMp3Path, 'eric');
            chgrp($trashMp3Path, 'audio');
            chmod($trashMp3Path, 0664);

            $mp3Size = filesize($trashMp3Path);
            $totalMp3Size += $mp3Size;

            $movedMp3Files[] = [
                'original_path' => $mp3File,
                'trash_path' => $trashMp3Path,
                'basename' => $mp3Basename,
                'size' => $mp3Size,
            ];
        }

        $m4aSize = filesize($newM4aPath);

        $metadata = [
            'deleted_at' => date('c'),
            'operation' => 'mp3_to_m4a_replacement',
            'book_number' => $bookNum,
            'directory' => $targetDir,
            'mp3_files' => array_map(function ($f) {
                return $f['basename'];
            }, $movedMp3Files),
            'mp3_file_count' => count($mp3Files),
            'm4a_file' => basename($m4aFile),
            'total_mp3_size' => $totalMp3Size,
            'm4a_size' => $m4aSize,
            'savings' => $totalMp3Size - $m4aSize,
        ];

        $metadataPath = $trashItemPath . '/metadata.json';
        file_put_contents($metadataPath, json_encode($metadata, JSON_PRETTY_PRINT));
        chmod($metadataPath, 0664);
        chown($metadataPath, 'eric');
        chgrp($metadataPath, 'audio');

        if (!unlink($m4aFile)) {
            throw new Exception("Failed to delete M4A file from source directory");
        }

        chown($newM4aPath, 'eric');
        chgrp($newM4aPath, 'audio');
        chmod($newM4aPath, 0664);
    }

    private function displayReport(): void
    {
        echo "\n";
        echo str_repeat('=', 80) . "\n";
        echo "REPLACEMENT REPORT\n";
        echo str_repeat('=', 80) . "\n\n";

        echo "Summary:\n";
        echo "  Total replacements: {$this->stats['total_replacements']}\n";
        echo "  Skipped (M4A larger): {$this->stats['total_skipped_larger_m4a']}\n";
        echo "  Skipped (no match): {$this->stats['total_skipped_no_match']}\n";
        echo "  Errors: " . count($this->stats['errors']) . "\n";
        echo "  Total storage saved: " . $this->formatBytes($this->stats['total_bytes_saved']) . "\n\n";

        if ($this->stats['total_multi_mp3_dirs'] > 0) {
            echo "WARNING: Directories with multiple MP3 files (flagged for manual review):\n";
            echo str_repeat('-', 80) . "\n";
            foreach ($this->stats['multi_mp3_dirs'] as $dir) {
                echo sprintf(
                    "  Book %03d: %s (%d MP3 files)\n",
                    $dir['book_num'],
                    $dir['dir_name'],
                    $dir['mp3_count']
                );
            }
            echo "\n";
            echo "These directories were SKIPPED and may need to be reverted by putting the MP3s back.\n\n";
        }

        if (count($this->stats['errors']) > 0) {
            echo "Errors encountered:\n";
            echo str_repeat('-', 80) . "\n";
            foreach ($this->stats['errors'] as $error) {
                echo "  Book {$error['book_num']}: {$error['error']}\n";
            }
            echo "\n";
        }

        if ($this->dryRun) {
            echo "\n*** This was a DRY RUN - No actual changes were made ***\n";
        }
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2) . ' ' . $units[$pow];
    }
}

// Script configuration
$mp3Dir = '/media/lyra_data1/audiobooks/books/Science Fiction/James Axler/Deathlands (GraphicAudio)';
$m4aDir = '/media/download/James Axler';
$trashDir = '/media/audiobooks/trash';
$dryRun = in_array('--dry-run', $argv) || in_array('-n', $argv);
$includeMultiMp3 = in_array('--include-multi', $argv) || in_array('--multi', $argv);

if (in_array('--help', $argv) || in_array('-h', $argv)) {
    echo "Usage: {$argv[0]} [OPTIONS]\n\n";
    echo "Replace MP3 audiobook files with smaller M4A versions.\n\n";
    echo "Options:\n";
    echo "  --dry-run, -n         Show what would be done without making changes\n";
    echo "  --include-multi       Include directories with multiple MP3 files\n";
    echo "  --help, -h            Show this help message\n\n";
    echo "Examples:\n";
    echo "  {$argv[0]} --dry-run              # Preview changes\n";
    echo "  {$argv[0]}                        # Replace single MP3 files only\n";
    echo "  {$argv[0]} --include-multi        # Replace all files including multi-MP3 dirs\n";
    exit(0);
}

// Run the script
try {
    $replacer = new AudiobookReplacer($mp3Dir, $m4aDir, $trashDir, $dryRun, $includeMultiMp3);
    $replacer->run();
    exit(0);
} catch (Exception $e) {
    echo "FATAL ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
