<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class AudioMetadataController extends Controller
{
    /**
     * Get metadata for an audio file
     */
    public function getMetadata(Request $request)
    {
        $filePath = $request->input('file');

        if (!$filePath) {
            return response()->json(['error' => 'File path required'], 400);
        }

        $storagePath = rtrim(config('filesystems.disks.books.root') ?? config('app.book_root'), '/');
        $fullPath = $storagePath . '/' . ltrim($filePath, '/');

        if (!file_exists($fullPath)) {
            return response()->json(['error' => 'File not found'], 404);
        }

        if (!is_file($fullPath)) {
            return response()->json(['error' => 'Not a file'], 400);
        }

        // Check if it's an audio file
        $extension = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
        if (!in_array($extension, ['m4b', 'm4a', 'mp3', 'aac', 'flac', 'ogg', 'wav'])) {
            return response()->json(['error' => 'Not an audio file'], 400);
        }

        try {
            $metadata = $this->extractMetadata($fullPath);

            return response()->json([
                'success' => true,
                'file' => basename($fullPath),
                'path' => $filePath,
                'metadata' => $metadata,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to extract audio metadata', [
                'file' => $fullPath,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Failed to extract metadata'], 500);
        }
    }

    /**
     * Extract metadata from audio file using ffprobe
     */
    private function extractMetadata(string $filePath): array
    {
        $metadata = [
            'format' => [],
            'streams' => [],
            'tags' => [],
        ];

        // Try ffprobe first
        if ($this->commandExists('ffprobe')) {
            $metadata = $this->extractWithFfprobe($filePath);
        }

        // Add file info
        $metadata['file'] = [
            'size' => filesize($filePath),
            'size_formatted' => $this->formatBytes(filesize($filePath)),
            'modified' => date('Y-m-d H:i:s', filemtime($filePath)),
            'extension' => pathinfo($filePath, PATHINFO_EXTENSION),
        ];

        return $metadata;
    }

    /**
     * Extract metadata using ffprobe
     */
    private function extractWithFfprobe(string $filePath): array
    {
        $process = new Process([
            'ffprobe',
            '-v',
            'quiet',
            '-print_format',
            'json',
            '-show_format',
            '-show_streams',
            $filePath,
        ]);
        $process->run();
        $output = $process->getOutput();

        if (!$output) {
            return ['error' => 'ffprobe returned no output'];
        }

        $data = json_decode($output, true);

        if (!$data) {
            return ['error' => 'Failed to parse ffprobe output'];
        }

        $metadata = [];

        // Format information
        if (isset($data['format'])) {
            $format = $data['format'];
            $metadata['format'] = [
                'filename' => basename($format['filename'] ?? ''),
                'format_name' => $format['format_name'] ?? 'Unknown',
                'format_long_name' => $format['format_long_name'] ?? 'Unknown',
                'duration' => isset($format['duration']) ? round((float) $format['duration'], 2) : null,
                'duration_formatted' => isset($format['duration']) ? $this->formatDuration((float) $format['duration']) : null,
                'size' => isset($format['size']) ? (int) $format['size'] : null,
                'size_formatted' => isset($format['size']) ? $this->formatBytes((int) $format['size']) : null,
                'bit_rate' => isset($format['bit_rate']) ? (int) $format['bit_rate'] : null,
                'bit_rate_formatted' => isset($format['bit_rate']) ? round((int) $format['bit_rate'] / 1000) . ' kbps' : null,
            ];

            // Tags
            if (isset($format['tags'])) {
                $metadata['tags'] = $format['tags'];
            }
        }

        // Stream information
        if (isset($data['streams']) && is_array($data['streams'])) {
            foreach ($data['streams'] as $stream) {
                if (($stream['codec_type'] ?? '') === 'audio') {
                    $metadata['streams'][] = [
                        'codec_name' => $stream['codec_name'] ?? 'Unknown',
                        'codec_long_name' => $stream['codec_long_name'] ?? 'Unknown',
                        'sample_rate' => isset($stream['sample_rate']) ? (int) $stream['sample_rate'] : null,
                        'sample_rate_formatted' => isset($stream['sample_rate']) ? round((int) $stream['sample_rate'] / 1000, 1) . ' kHz' : null,
                        'channels' => $stream['channels'] ?? null,
                        'channel_layout' => $stream['channel_layout'] ?? null,
                        'bit_rate' => isset($stream['bit_rate']) ? (int) $stream['bit_rate'] : null,
                        'bit_rate_formatted' => isset($stream['bit_rate']) ? round((int) $stream['bit_rate'] / 1000) . ' kbps' : null,
                    ];
                }
            }
        }

        return $metadata;
    }

    /**
     * Check if a command exists
     */
    private function commandExists(string $command): bool
    {
        $process = new Process([$command, '-version']);
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * Format bytes to human readable
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * Format duration in seconds to HH:MM:SS
     */
    private function formatDuration(float $seconds): string
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = floor($seconds % 60);

        return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
    }
}
