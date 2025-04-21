<?php

namespace App\Traits;

use App\Models\Book;
use App\Models\Genre;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Illuminate\Support\Facades\Log;

trait BookImportTrait
{
    private function importBooksFromDirectory($libraryPath)
    {
        $this->processGenres($libraryPath);
    }

    private function processGenres($libraryPath)
    {
        $genres = $this->scanDirectory($libraryPath);
        foreach ($genres as $genre) {
            if ($genre === '.' || $genre === '..')
                continue;
            $genrePath = $libraryPath . '/' . $genre;

            if (is_dir($genrePath)) {
                $this->processAuthors($genrePath, $genre);
            }
        }
    }

    private function processAuthors($genrePath, $genre)
    {
        $authors = $this->scanDirectory($genrePath);
        foreach ($authors as $author) {
            if ($author === '.' || $author === '..')
                continue;
            $authorPath = $genrePath . '/' . $author;

            if (is_dir($authorPath)) {
                $this->processSeriesOrBooks($authorPath, $genre, $author);
            }
        }
    }

    private function processSeriesOrBooks($authorPath, $genre, $author)
    {
        $seriesOrBooks = $this->scanDirectory($authorPath);
        foreach ($seriesOrBooks as $seriesOrBook) {
            if ($seriesOrBook === '.' || $seriesOrBook === '..')
                continue;
            $seriesOrBookPath = $authorPath . '/' . $seriesOrBook;

            if (is_dir($seriesOrBookPath)) { //Could be series or Book
                $bookPath = $seriesOrBookPath;
                $series = null;
                $bookDirName = $seriesOrBook;

                $files = $this->scanDirectory($seriesOrBookPath);
                $bookTitle = null;
                foreach ($files as $file) {
                    if ($file === '.' || $file === '..')
                        continue;
                    if (is_dir($seriesOrBookPath . '/' . $file)) {
                        $series = $seriesOrBook;
                        $bookDirName = $file;
                        $bookPath = $seriesOrBookPath . '/' . $file;
                        break;
                    } else {
                        $bookTitle = $seriesOrBook;
                    }
                }

                if (!$bookTitle)
                    $bookTitle = $bookDirName;

                $this->createBook($genre, $author, $series, $bookTitle, $bookPath);
            }
        }
    }

    private function scanDirectory($path)
    {
        $storagePath = env('BOOK_STORAGE_PATH');

        if (!$storagePath) {
            Log::error('BOOK_STORAGE_PATH is not defined in the .env file.');
            return response()->json(['message' => 'The token could not be found and is not able to search or implement a path. Please check security of the system and notify the system admin.'], 400);
        }

        $files = scandir($path);
        if ($files === false) {
            Log::error('Attempt to perform a scandir but failed to get value.');
            return response()->json(['message' => 'Attempt to get the folder or information of the folder with failure call. Please check if it is installed. Please check security of the system and notify the system admin.'], 400);
        }
        return $files;
    }

    private function extractTagData($filePath)
    {
        $storagePath = env('BOOK_STORAGE_PATH');
        if (!$storagePath) {
            Log::error('BOOK_STORAGE_PATH is not defined in the .env file.');
            return response()->json(['message' => 'The token could not be found and is not able to be located for information . Please check security of the system and notify the system admin.'], 400);
        }
        $directoryPath = dirname($filePath);
        $process = new Process([
            'ffmpeg',
            '-i',
            $filePath,
            '-f',
            'ffmetadata',
            'pipe:1'  // Output to standard output
        ]);

        $process->run();

        if (!$process->isSuccessful()) {
            return []; // Return empty array if FFmpeg fails
        }

        $output = $process->getOutput();
        $lines = explode("\n", $output);

        $tags = [];
        foreach ($lines as $line) {
            if (strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2); // Limit to 2 parts in case value also contains '='
                $tags[trim($key)] = trim($value);
            }
        }

        $title = $tags['title'] ?? null;
        $artist = $tags['artist'] ?? null;
        $album = $tags['album'] ?? null;
        $comment = $tags['comment'] ?? $tags['description'] ?? null;

        // Check if tags match the directory structure
        $tagMatch = true;

        if ($artist && !str_contains(strtolower($directoryPath), strtolower($artist))) {
            $tagMatch = false;
        }

        if ($album && !str_contains(strtolower($directoryPath), strtolower($album))) {
            $tagMatch = false;
        }

        return [
            'title' => $title,
            'artist' => $artist,
            'album' => $album,
            'description' => $comment,
            'tagMatch' => $tagMatch,
        ];
    }
}
