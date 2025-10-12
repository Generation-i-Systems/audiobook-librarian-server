<?php

namespace App\Services;

use getID3;
use getid3_lib;

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
            // Log error if needed
            // logger()->error('Error analyzing audio file: ' . $e->getMessage());
            return null;
        }

        return null;
    }

    /**
     * Format seconds into HH:MM:SS format
     */
    public function formatDuration(float $seconds): string
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $seconds = floor($seconds % 60);

        return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
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
                if (isset($comments['title'][0])) {
                    $metadata['title'] = $comments['title'][0];
                }

                if (isset($comments['artist'][0])) {
                    $metadata['author'] = [$comments['artist'][0]];
                }

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
            return null;
        }
    }
}
