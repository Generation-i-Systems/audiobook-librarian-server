<?php

namespace App\Services;

use getID3;
use getid3_lib;
use Illuminate\Support\Facades\Log;

class AudioFileAnalyzer
{
    /**
     * @var getID3
     */
    protected $getID3;

    /**
     * Supported audio file extensions
     *
     * @var array
     */
    protected $supportedExtensions = [
        'mp3',
        'm4a',
        'm4b',
        'm4p',
        'mp4',
        'aac',
        'ogg',
        'oga',
        'wav',
        'flac',
        'wma',
    ];

    /**
     * Create a new AudioFileAnalyzer instance.
     */
    public function __construct()
    {
        $this->getID3 = new getID3();
        // Disable writing tags to files
        $this->getID3->option_tag_id3v1 = false;
        $this->getID3->option_tag_id3v2 = false;
        $this->getID3->option_tag_lyrics3 = false;
        $this->getID3->option_tags_process = false;
    }

    /**
     * Get the getID3 instance for direct access
     */
    public function getID3Analyzer(): \getID3
    {
        return $this->getID3;
    }

    /**
     * Check if an audio file is valid using ffprobe (primary) and getID3 (fallback)
     *
     * @return array{valid: bool, error?: string, duration?: float, format?: string}
     */
    public function validateAudioFile(string $filePath): array
    {
        if (!file_exists($filePath) || !is_file($filePath)) {
            return ['valid' => false, 'error' => 'File does not exist'];
        }

        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if (!in_array($extension, $this->supportedExtensions)) {
            return ['valid' => false, 'error' => 'Unsupported file extension: ' . $extension];
        }

        // Try ffprobe first (most reliable for all formats including m4b, m4a, etc.)
        $ffprobeResult = $this->validateWithFFprobe($filePath);
        if ($ffprobeResult['valid']) {
            return $ffprobeResult;
        }

        // Fallback to getID3
        return $this->validateWithGetID3($filePath);
    }

    /**
     * Validate using ffprobe
     *
     * @return array{valid: bool, error?: string, duration?: float, format?: string}
     */
    private function validateWithFFprobe(string $filePath): array
    {
        if (!$this->commandExists('ffprobe')) {
            return ['valid' => false, 'error' => 'ffprobe not available'];
        }

        try {
            $escapedPath = escapeshellarg($filePath);
            $command = "ffprobe -v quiet -print_format json -show_format -show_streams {$escapedPath} 2>&1";
            $output = shell_exec($command);

            if (empty($output)) {
                return ['valid' => false, 'error' => 'ffprobe returned no output'];
            }

            $data = json_decode($output, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return ['valid' => false, 'error' => 'Failed to parse ffprobe output: ' . json_last_error_msg()];
            }

            // Check for audio stream
            $hasAudioStream = false;
            if (isset($data['streams']) && is_array($data['streams'])) {
                foreach ($data['streams'] as $stream) {
                    if (isset($stream['codec_type']) && $stream['codec_type'] === 'audio') {
                        $hasAudioStream = true;
                        break;
                    }
                }
            }

            if (!$hasAudioStream) {
                return ['valid' => false, 'error' => 'No audio stream found'];
            }

            // Check format
            $format = $data['format']['format_name'] ?? null;
            $duration = isset($data['format']['duration']) ? (float) $data['format']['duration'] : null;

            return [
                'valid' => true,
                'duration' => $duration,
                'format' => $format,
            ];
        } catch (\Throwable $e) {
            return ['valid' => false, 'error' => 'ffprobe exception: ' . $e->getMessage()];
        }
    }

    /**
     * Validate using getID3
     *
     * @return array{valid: bool, error?: string, duration?: float, format?: string}
     */
    private function validateWithGetID3(string $filePath): array
    {
        try {
            $fileInfo = $this->getID3->analyze($filePath);

            if (isset($fileInfo['error'])) {
                return ['valid' => false, 'error' => 'getID3 error: ' . implode(', ', $fileInfo['error'])];
            }

            if (!isset($fileInfo['audio'])) {
                return ['valid' => false, 'error' => 'No audio track found'];
            }

            if (empty($fileInfo['audio']['dataformat'])) {
                return ['valid' => false, 'error' => 'No audio data format'];
            }

            $duration = isset($fileInfo['playtime_seconds']) ? (float) $fileInfo['playtime_seconds'] : null;

            return [
                'valid' => true,
                'duration' => $duration,
                'format' => $fileInfo['audio']['dataformat'] ?? null,
            ];
        } catch (\Throwable $e) {
            return ['valid' => false, 'error' => 'getID3 exception: ' . $e->getMessage()];
        }
    }

    /**
     * Check if a command exists on the system
     */
    private function commandExists(string $command): bool
    {
        $which = shell_exec('which ' . $command);
        return !empty(trim($which));
    }

    /**
     * Get the duration of an audio file in seconds
     *
     * @return float|null Duration in seconds, or null if not an audio file or error
     */
    public function getAudioDuration(string $filePath): ?float
    {
        if (!file_exists($filePath) || !is_file($filePath)) {
            return null;
        }

        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if (!in_array($extension, $this->supportedExtensions)) {
            return null;
        }

        try {
            $fileInfo = $this->getID3->analyze($filePath);
            getid3_lib::CopyTagsToComments($fileInfo);

            if (isset($fileInfo['playtime_seconds'])) {
                return (float) $fileInfo['playtime_seconds'];
            }
        } catch (\Exception $e) {
            Log::warning('AudioFileAnalyzer: Failed to get audio duration', [
                'file' => $filePath,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        return null;
    }

    /**
     * Format seconds into HH:MM:SS format
     */
    public function formatDuration(float $seconds): string
    {
        $total = (int) $seconds;
        $hours = intdiv($total, 3600);
        $minutes = intdiv($total % 3600, 60);
        $secs = $total % 60;

        return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
    }

    /**
     * Get total duration of all audio files in a directory (recursively)
     *
     * @return array ['total_seconds' => float, 'formatted' => string, 'file_count' => int]
     */
    public function getDirectoryAudioDuration(string $directory): array
    {
        $totalSeconds = 0;
        $fileCount = 0;
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if ($file->isFile()) {
                $duration = $this->getAudioDuration($file->getPathname());
                if ($duration !== null) {
                    $totalSeconds += $duration;
                    $fileCount++;
                }
            }
        }

        return [
            'total_seconds' => $totalSeconds,
            'formatted' => $this->formatDuration($totalSeconds),
            'file_count' => $fileCount,
        ];
    }

    /**
     * Analyze directory and extract metadata from audio files
     *
     * @param  string  $directory  Path to directory containing audio files
     * @return array|null Metadata extracted from audio files, or null if no metadata found
     */
    public function analyzeDirectory(string $directory): ?array
    {
        // Handle single file path
        if (is_file($directory)) {
            $extension = strtolower(pathinfo($directory, PATHINFO_EXTENSION));
            if (in_array($extension, $this->supportedExtensions)) {
                $audioFile = $directory;
            } else {
                return null;
            }
        } elseif (is_dir($directory)) {
            // Find first audio file to extract metadata from
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS)
            );

            $audioFile = null;
            foreach ($files as $file) {
                if ($file->isFile()) {
                    $extension = strtolower($file->getExtension());
                    if (in_array($extension, $this->supportedExtensions)) {
                        $audioFile = $file->getPathname();
                        break;
                    }
                }
            }

            if (!$audioFile) {
                return null;
            }
        } else {
            return null;
        }

        try {
            $analyzeStartTime = microtime(true);
            $fileInfo = $this->getID3->analyze($audioFile);
            $analyzeDuration = round((microtime(true) - $analyzeStartTime) * 1000);

            $copyStartTime = microtime(true);
            getid3_lib::CopyTagsToComments($fileInfo);
            $copyDuration = round((microtime(true) - $copyStartTime) * 1000);

            if (getenv('VERBOSE_TIMING')) {
                echo "    ⏱️  getID3->analyze() took: {$analyzeDuration}ms\n";
                echo "    ⏱️  CopyTagsToComments() took: {$copyDuration}ms\n";
            }

            $metadata = [
                'confidence' => 75, // Default: Audio file metadata has medium confidence
            ];

            // For M4B/M4A files, check quicktime tags first
            $comments = null;
            if (isset($fileInfo['quicktime']['comments'])) {
                $comments = $fileInfo['quicktime']['comments'];
            } elseif (isset($fileInfo['comments'])) {
                $comments = $fileInfo['comments'];
            }

            if ($comments) {
                // Extract common metadata fields
                // Prefer 'album' tag for title as it's more likely to be the book title
                // The 'title' tag often contains chapter titles
                if (isset($comments['album'][0]) && !empty(trim($comments['album'][0]))) {
                    $metadata['title'] = $comments['album'][0];
                } elseif (isset($comments['title'][0])) {
                    $title = $comments['title'][0];

                    // Skip common chapter titles that are not book titles
                    $invalidTitles = [
                        'opening credits',
                        'closing credits',
                        'end credits',
                        'credits',
                        'prologue',
                        'epilogue',
                        'chapter 1',
                        'chapter one',
                        'chapter 01',
                        'introduction',
                        'intro',
                        'part 1',
                        'part one',
                        'part 01',
                        'track 1',
                        'track one',
                        'track 01',
                        'untitled',
                        'audiobook',
                    ];

                    if (!in_array(strtolower(trim($title)), $invalidTitles)) {
                        $metadata['title'] = $title;
                    }
                }

                if (isset($comments['artist'][0])) {
                    $metadata['author'] = [$comments['artist'][0]];
                }

                // Use 'album_artist' for series if available, otherwise use 'album' only if we didn't use it for title
                if (isset($comments['album'][0])) {
                    $metadata['series'] = $comments['album'][0];
                }

                if (isset($comments['genre'][0])) {
                    $metadata['genre'] = [$comments['genre'][0]];
                }

                if (isset($comments['year'][0])) {
                    $metadata['year'] = $comments['year'][0];
                } elseif (isset($comments['creation_date'][0])) {
                    $metadata['year'] = substr($comments['creation_date'][0], 0, 4);
                }

                if (isset($comments['publisher'][0])) {
                    $metadata['publisher'] = $comments['publisher'][0];
                }

                if (isset($comments['narrator'][0])) {
                    $metadata['narrator'] = [$comments['narrator'][0]];
                }
            }

            // Get duration
            $durationStartTime = microtime(true);
            if (is_file($directory)) {
                // Single file - get its duration directly
                $duration = $this->getAudioDuration($audioFile);
                if ($duration !== null && $duration > 0) {
                    $metadata['duration'] = (int) $duration;
                }
            } else {
                // Directory - sum all audio file durations
                $durationInfo = $this->getDirectoryAudioDuration($directory);
                if ($durationInfo['total_seconds'] > 0) {
                    $metadata['duration'] = (int) $durationInfo['total_seconds'];
                }
            }
            $durationDuration = round((microtime(true) - $durationStartTime) * 1000);

            if (getenv('VERBOSE_TIMING') && $durationDuration > 10) {
                echo "    ⏱️  Duration calculation took: {$durationDuration}ms\n";
            }

            // Only return metadata if we found at least title or author
            if (isset($metadata['title']) || isset($metadata['author'])) {
                // Adjust confidence based on metadata completeness
                $fieldsFound = 0;
                $criticalFields = ['title', 'author', 'series', 'genre', 'year'];
                foreach ($criticalFields as $field) {
                    if (isset($metadata[$field]) && !empty($metadata[$field])) {
                        $fieldsFound++;
                    }
                }

                // Increase confidence if we have most metadata fields
                // 5/5 fields = 95%, 4/5 = 90%, 3/5 = 85%, 2/5 = 80%, 1/5 = 75%
                if ($fieldsFound >= 4) {
                    $metadata['confidence'] = 90 + ($fieldsFound - 4) * 5;
                } elseif ($fieldsFound >= 2) {
                    $metadata['confidence'] = 75 + ($fieldsFound - 2) * 5;
                }

                return $metadata;
            }

            return null;
        } catch (\Exception $e) {
            Log::error('AudioFileAnalyzer: Failed to analyze audio file', [
                'file' => $audioFile ?? $directory,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }
}
