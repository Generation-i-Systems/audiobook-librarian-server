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
        'mp3', 'm4a', 'm4b', 'm4p', 'mp4', 'aac', 'ogg', 'oga', 'wav', 'flac', 'wma'
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
     * @param string $filePath
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
     *
     * @param float $seconds
     * @return string
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
     * @param string $directory
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
            'file_count' => $fileCount
        ];
    }
}
