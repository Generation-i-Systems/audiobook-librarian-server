<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use Illuminate\Support\Facades\Storage;

trait DirectoryConflictTrait
{
    /**
     * Check for directory conflicts
     */
    private function checkDirectoryConflict(string $newPath): array
    {
        $booksDisk = Storage::disk('books');
        $booksRoot = config('app.book_root', config('app.book_root'));

        $newPath = trim($newPath, '/');
        if ($newPath === '') {
            return ['hasConflict' => false, 'reason' => ''];
        }

        $fullPath = $booksRoot . '/' . ltrim($newPath, '/');

        if (!is_dir($fullPath)) {
            return ['hasConflict' => false, 'reason' => 'new_directory_does_not_exist'];
        }

        $files = $booksDisk->allFiles($newPath);

        if (empty($files)) {
            return ['hasConflict' => false, 'reason' => 'new_directory_empty'];
        }

        $audioExtensions = ['mp3', 'm4a', 'm4b', 'flac', 'ogg', 'opus', 'aac', 'wav', 'wma'];
        $hasAudioFiles = false;

        foreach ($files as $file) {
            $filename = basename($file);
            $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

            if (in_array($extension, $audioExtensions)) {
                $hasAudioFiles = true;
                break;
            }
        }

        if ($hasAudioFiles) {
            return [
                'hasConflict' => true,
                'reason' => 'directory_contains_audio_files',
            ];
        }

        return ['hasConflict' => false, 'reason' => 'new_directory_contains_only_metadata'];
    }
}
