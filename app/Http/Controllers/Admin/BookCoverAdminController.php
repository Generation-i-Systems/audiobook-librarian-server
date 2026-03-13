<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BookCoverAdminController extends Controller
{
    /**
     * Extract embedded cover from audio files in the book directory
     */
    public function extractEmbeddedCover(Request $request)
    {
        $directoryPath = $request->input('directory_path');

        if (!$directoryPath) {
            return response()->json(['error' => 'Directory path is required'], 400);
        }

        // Get the full path to the book directory
        $bookRoot = rtrim(config('app.book_root', '/media/lyra_data1/audiobooks/books'), '/');
        $fullDirectoryPath = $bookRoot . '/' . ltrim($directoryPath, '/');

        if (!is_dir($fullDirectoryPath)) {
            return response()->json(['error' => 'Directory not found: ' . $directoryPath], 404);
        }

        // Initialize getID3
        if (!class_exists('\getID3')) {
            return response()->json(['error' => 'getID3 library not available'], 500);
        }

        $getID3 = new \getID3();
        $getID3->option_tag_id3v1 = false;
        $getID3->option_tag_id3v2 = false;
        $getID3->option_tag_lyrics3 = false;
        $getID3->option_tags_process = true; // Enable tag processing to get embedded covers

        // Find audio files in the directory
        $audioFiles = glob($fullDirectoryPath . '/*.{mp3,m4a,m4b,m4p,mp4,aac,ogg,oga,wav,flac,wma}', GLOB_BRACE);

        if (empty($audioFiles)) {
            return response()->json(['error' => 'No audio files found in directory'], 404);
        }

        $extractedCovers = [];

        foreach ($audioFiles as $audioFile) {
            try {
                $info = $getID3->analyze($audioFile);

                // Check for embedded cover in various formats
                $coverData = null;
                $mimeType = 'image/jpeg';

                // Check QuickTime/M4B tags
                if (!empty($info['comments']['picture'][0])) {
                    $coverData = $info['comments']['picture'][0]['data'];
                    if (!empty($info['comments']['picture'][0]['image_mime'])) {
                        $mimeType = $info['comments']['picture'][0]['image_mime'];
                    }
                }

                // Check ID3v2 tags (MP3)
                if (!$coverData && !empty($info['id3v2']['APIC'])) {
                    foreach ($info['id3v2']['APIC'] as $pic) {
                        if ($pic['picturetypeid'] === 3 || $pic['picturetypeid'] === 0) { // Cover(3) or Other(0)
                            $coverData = $pic['data'];
                            if (!empty($pic['mime'])) {
                                $mimeType = $pic['mime'];
                            }
                            break;
                        }
                    }
                }

                // Check ASF/WMV tags
                if (!$coverData && !empty($info['asf']['comments']['picture'])) {
                    foreach ($info['asf']['comments']['picture'] as $pic) {
                        if (!empty($pic['data'])) {
                            $coverData = $pic['data'];
                            if (!empty($pic['image_mime'])) {
                                $mimeType = $pic['image_mime'];
                            }
                            break;
                        }
                    }
                }

                // Check OGG Vorbis tags
                if (!$coverData && !empty($info['ogg']['comments']['coverart'][0])) {
                    $coverData = base64_decode($info['ogg']['comments']['coverart'][0]);
                    // OGG doesn't always store mime type, default to jpeg
                }

                if ($coverData) {
                    // Create temporary file for the cover
                    $extension = $this->getExtensionFromMimeType($mimeType);
                    $tempFile = tempnam(sys_get_temp_dir(), 'embedded_cover_') . '.' . $extension;

                    if (file_put_contents($tempFile, $coverData)) {
                        $extractedCovers[] = [
                            'file' => basename($audioFile),
                            'temp_path' => $tempFile,
                            'mime_type' => $mimeType,
                            'size' => strlen($coverData),
                            'url' => 'data:' . $mimeType . ';base64,' . base64_encode($coverData)
                        ];
                    }
                }
            } catch (\Exception $e) {
                Log::warning('Failed to extract cover from audio file', [
                    'file' => basename($audioFile),
                    'error' => $e->getMessage()
                ]);
            }
        }

        if (empty($extractedCovers)) {
            return response()->json(['error' => 'No embedded covers found in audio files'], 404);
        }

        return response()->json([
            'success' => true,
            'covers' => $extractedCovers,
            'message' => 'Found ' . count($extractedCovers) . ' embedded cover(s)'
        ]);
    }

    /**
     * Get file extension from MIME type
     */
    private function getExtensionFromMimeType(string $mimeType): string
    {
        $extensions = [
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
        ];

        return $extensions[$mimeType] ?? 'jpg';
    }
}
