<?php

namespace App\Services;

use App\Models\Author;
use App\Models\Book;
use App\Models\Genre;
use App\Models\Narrator;
use App\Models\Publisher;
use App\Models\Series;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class BookImportService
{
    /**
     * Create book from metadata with comprehensive handling
     */
    public function createBookFromMetadata(array $metadata, array $audiobook, array $options = []): ?Book
    {
        try {
            DB::beginTransaction();

            $book = new Book();
            $book->title = $metadata['title'] ?? 'Unknown Title';
            $book->description = $metadata['description'] ?? null;
            
            // Handle year/release_date
            if (isset($metadata['year']) && $metadata['year']) {
                $book->release_date = $metadata['year'] . '-01-01'; // Convert year to date
            }
            
            $book->isbn = $metadata['isbn'] ?? null;
            $book->language = $metadata['language'] ?? 'en';
            $book->source = 'import';
            
            // Store directory path for the audiobook files
            $book->directory_path = $this->generateDirectoryPath($metadata);
            
            // Handle duration (should be in seconds as integer)
            if (isset($metadata['duration'])) {
                if (is_string($metadata['duration']) && preg_match('/(\d{2}):(\d{2}):(\d{2})/', $metadata['duration'], $matches)) {
                    // Convert HH:MM:SS to seconds
                    $book->duration = ($matches[1] * 3600) + ($matches[2] * 60) + $matches[3];
                } elseif (is_numeric($metadata['duration'])) {
                    $book->duration = (int)$metadata['duration'];
                }
            }
            
            // Handle cover image with download support
            if (!empty($metadata['cover_path'])) {
                $book->cover_image = $metadata['cover_path'];
            } elseif (!empty($metadata['cover_url'])) {
                $source = isset($metadata['audible_raw']) ? 'audible' : 'googlebooks';
                $coverPath = $this->downloadCoverImage($metadata['cover_url'], $book->directory_path, $source);
                if ($coverPath) {
                    $book->cover_image = $coverPath;
                } else {
                    $book->cover_image = $metadata['cover_url'];
                }
            }

            // Handle publisher
            if (!empty($metadata['publisher'])) {
                $publisher = is_array($metadata['publisher']) ? $metadata['publisher'][0] : $metadata['publisher'];
                $book->publisher = $publisher;
            }

            // Store enrichment data in proper JSON columns
            if (!empty($metadata['audible_raw'])) {
                $book->audible_info = $metadata['audible_raw'];
            }

            if (!empty($metadata['google_books_raw'])) {
                $book->google_books_info = $metadata['google_books_raw'];
            }
            
            if (!empty($metadata['audiobook_bay_raw'])) {
                $book->audiobook_bay_info = $metadata['audiobook_bay_raw'];
            }
            
            // Calculate and store audio file information if available
            if (!empty($audiobook['files'])) {
                $audioInfo = $this->calculateAudioInfo($audiobook['files']);
                $book->audio_file_count = $audioInfo['count'];
                if ($audioInfo['duration'] > 0) {
                    $book->duration = $audioInfo['duration'];
                }
                $book->file_tags = $audioInfo['tags'];
            }
            
            // Set data source
            $book->source = $options['data_source'] ?? 'import';

            $book->save();

            // Handle authors
            if (!empty($metadata['author'])) {
                $authors = is_array($metadata['author']) ? $metadata['author'] : [$metadata['author']];
                $authorIds = [];
                foreach ($authors as $authorName) {
                    $author = Author::firstOrCreate(['name' => trim($authorName)]);
                    $authorIds[] = $author->id;
                }
                $book->authors()->sync($authorIds);
            }

            // Handle narrators
            if (!empty($metadata['narrator'])) {
                $narrators = is_array($metadata['narrator']) ? $metadata['narrator'] : [$metadata['narrator']];
                $narratorIds = [];
                foreach ($narrators as $narratorName) {
                    $narrator = Narrator::firstOrCreate(['name' => trim($narratorName)]);
                    $narratorIds[] = $narrator->id;
                }
                $book->narrators()->sync($narratorIds);
            }


            // Handle series with multi-book support
            if (!empty($metadata['series'])) {
                $series = Series::firstOrCreate(['name' => trim($metadata['series'])]);
                
                // Handle multi-book entries (e.g., books 2-3 combined)
                if (!empty($metadata['multi_book_numbers'])) {
                    $firstNumber = $metadata['multi_book_numbers'][0];
                    $lastNumber = end($metadata['multi_book_numbers']);
                    
                    $book->series()->sync([
                        $series->id => [
                            'series_number' => $firstNumber,
                            // Note: series_end_number field may need to be added to pivot table
                        ]
                    ]);
                } else {
                    $seriesNumber = $metadata['series_number'] ?? 1;
                    $book->series()->sync([
                        $series->id => ['series_number' => $seriesNumber]
                    ]);
                }
            }

            // Handle genres
            if (!empty($metadata['genre'])) {
                $genres = is_array($metadata['genre']) ? $metadata['genre'] : [$metadata['genre']];
                foreach ($genres as $genreName) {
                    $genre = Genre::firstOrCreate(['name' => trim($genreName)]);
                    $book->genres()->attach($genre->id);
                }
            }

            DB::commit();
            return $book;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to create book from metadata: " . $e->getMessage(), [
                'metadata' => $metadata,
                'audiobook' => $audiobook,
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * Generate directory path for book with advanced features
     */
    public function generateDirectoryPath(array $metadata, array $options = []): string
    {
        // If custom directory path is set, use it
        if (!empty($metadata['custom_directory_path'])) {
            return trim($metadata['custom_directory_path']);
        }
        
        $structure = $options['directory_structure'] ?? 'genre/author/series';
        $authors = is_array($metadata['author']) ? $metadata['author'] : [$metadata['author']];
        
        // Handle comma-separated authors
        if (count($authors) === 1 && strpos($authors[0], ',') !== false) {
            $authors = array_map('trim', explode(',', $authors[0]));
        }
        
        // Handle genre - convert array to string
        $genreData = $metadata['genre'] ?? 'Unknown';
        $genre = is_array($genreData) ? $genreData[0] : $genreData;
        if (empty($genre)) {
            $genre = 'Unknown';
        }
        
        // Check for existing author directory first
        $cleanedSeries = null;
        if (!empty($metadata['series'])) {
            $cleanedSeries = $this->cleanSeriesName($metadata['series'], $authors);
        }
        
        $existingAuthorDir = $this->findExistingAuthorDirectory($authors, $cleanedSeries);
        
        if ($existingAuthorDir) {
            $authorDir = $existingAuthorDir;
        } else {
            // Check for Graphic Audio in narrator field
            $authorDir = $this->formatAuthorsForDirectory($authors);
            if ($this->isGraphicAudio($metadata)) {
                $authorDir = 'Graphic Audio';
            }
        }
        
        $path = match ($structure) {
            'author/series' => $this->buildAuthorSeriesPath($authorDir, $metadata),
            'genre/author' => "{$genre}/{$authorDir}",
            'series/author' => $this->buildSeriesAuthorPath($metadata, $authorDir),
            'flat' => $authorDir,
            default => $this->buildGenreAuthorSeriesPath($genre, $authorDir, $metadata)
        };
        
        // Add title if requested
        if (!empty($metadata['title']) && ($options['include_title'] ?? false)) {
            $title = $metadata['title'];
            
            // If we have a series number, prefix it to the title
            if (!empty($metadata['series_number'])) {
                $seriesNumber = str_pad($metadata['series_number'], 2, '0', STR_PAD_LEFT);
                $title = $seriesNumber . ' ' . $title;
            }
            
            // Add GraphicAudio marker if detected
            $title = $this->addGraphicAudioMarker($title, $metadata);
            
            $path .= '/' . $title;
        }
        
        return $path;
    }

    /**
     * Build genre/author/series path structure
     */
    protected function buildGenreAuthorSeriesPath(string $genre, string $authorDir, array $metadata): string
    {
        if (!empty($metadata['series'])) {
            return "{$genre}/{$authorDir}/{$metadata['series']}";
        }
        return "{$genre}/{$authorDir}";
    }

    /**
     * Build author/series path structure
     */
    protected function buildAuthorSeriesPath(string $authorDir, array $metadata): string
    {
        if (!empty($metadata['series'])) {
            return "{$authorDir}/{$metadata['series']}";
        }
        return $authorDir;
    }

    /**
     * Build series/author path structure
     */
    protected function buildSeriesAuthorPath(array $metadata, string $authorDir): string
    {
        if (!empty($metadata['series'])) {
            return "{$metadata['series']}/{$authorDir}";
        }
        return "Standalone/{$authorDir}";
    }

    /**
     * Move files to library
     */
    public function moveFilesToLibrary(array $audiobook, Book $book, array $options = []): bool
    {
        try {
            $bookStoragePath = $options['storage_path'] ?? config('filesystems.disks.books.root') ?? env('BOOK_STORAGE_PATH');
            if (!$bookStoragePath) {
                throw new \Exception('Book storage path not configured');
            }

            $sourcePath = $audiobook['path'];
            $targetDir = $options['target_directory'] ?? $this->generateTargetDirectory($book, $bookStoragePath, $options);
            $operation = $options['operation'] ?? 'copy'; // 'copy' or 'move'

            // Handle directory conflicts
            if (File::isDirectory($targetDir)) {
                $targetDir = $this->handleDirectoryConflict($audiobook, $targetDir);
            }
            
            if (!File::isDirectory($targetDir)) {
                File::makeDirectory($targetDir, 0775, true);
            }

            // Flatten CD directories before moving files
            $this->flattenCdDirectories($sourcePath);

            if ($operation === 'move') {
                $this->moveDirectoryContents($sourcePath, $targetDir);
                // Clean up source directory after successful move
                $this->cleanupSourceDirectory($audiobook);
            } else {
                $this->copyDirectoryContents($sourcePath, $targetDir);
            }
            
            return true;

        } catch (\Exception $e) {
            Log::error("Failed to move files to library: " . $e->getMessage(), [
                'audiobook' => $audiobook,
                'book_id' => $book->id,
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Generate target directory for book
     */
    protected function generateTargetDirectory(Book $book, string $basePath, array $options = []): string
    {
        $authors = $book->authors->pluck('name')->toArray();
        $genre = $book->genres->first()?->name ?? 'Unknown';
        $authorDir = $this->formatAuthorsForDirectory($authors);
        
        $metadata = [
            'author' => $authors,
            'genre' => $genre,
            'series' => $book->series->first()?->name,
            'title' => $book->title
        ];
        
        $relativePath = $this->generateDirectoryPath($metadata, $options);
        $path = "{$basePath}/{$relativePath}";
        
        // Always include title in path unless explicitly disabled
        if (!isset($options['include_title_in_path']) || $options['include_title_in_path'] !== false) {
            $path .= "/{$book->title}";
        }
        
        return $path;
    }

    /**
     * Copy directory contents
     */
    protected function copyDirectoryContents(string $source, string $target): void
    {
        if (!File::isDirectory($source)) {
            throw new \Exception("Source directory does not exist: {$source}");
        }

        $files = File::allFiles($source);
        
        foreach ($files as $file) {
            $relativePath = $file->getRelativePathname();
            $targetFile = "{$target}/{$relativePath}";
            
            $targetSubDir = dirname($targetFile);
            if (!File::isDirectory($targetSubDir)) {
                File::makeDirectory($targetSubDir, 0775, true);
            }
            
            File::copy($file->getPathname(), $targetFile);
            
            // Set file permissions after copying
            chmod($targetFile, 0664);
        }
    }

    /**
     * Move directory contents
     */
    protected function moveDirectoryContents(string $source, string $target): void
    {
        if (!File::isDirectory($source)) {
            throw new \Exception("Source directory does not exist: {$source}");
        }

        $files = File::allFiles($source);
        
        foreach ($files as $file) {
            $relativePath = $file->getRelativePathname();
            $targetFile = "{$target}/{$relativePath}";
            
            $targetSubDir = dirname($targetFile);
            if (!File::isDirectory($targetSubDir)) {
                File::makeDirectory($targetSubDir, 0775, true);
            }
            
            File::move($file->getPathname(), $targetFile);
            
            // Set file permissions after moving
            chmod($targetFile, 0664);
        }

        // Remove empty directories from source
        $this->removeEmptyDirectories($source);
    }

    /**
     * Remove empty directories recursively
     */
    protected function removeEmptyDirectories(string $path): void
    {
        if (!File::isDirectory($path)) {
            return;
        }

        $directories = File::directories($path);
        
        foreach ($directories as $dir) {
            $this->removeEmptyDirectories($dir);
        }

        if ($this->isDirectoryEmpty($path)) {
            File::deleteDirectory($path);
        }
    }

    /**
     * Check if this is a Graphic Audio audiobook
     */
    protected function isGraphicAudio(array $metadata): bool
    {
        // Check narrator field
        if (!empty($metadata['narrator'])) {
            $narrators = is_array($metadata['narrator']) ? $metadata['narrator'] : [$metadata['narrator']];
            foreach ($narrators as $narrator) {
                if (is_string($narrator) && 
                    (stripos($narrator, 'Graphic Audio') !== false || 
                     stripos($narrator, 'GraphicAudio') !== false)) {
                    return true;
                }
            }
        }
        
        // Also check publisher field as fallback
        if (!empty($metadata['publisher'])) {
            $publishers = is_array($metadata['publisher']) ? $metadata['publisher'] : [$metadata['publisher']];
            foreach ($publishers as $publisher) {
                if (is_string($publisher) && 
                    (stripos($publisher, 'Graphic Audio') !== false || 
                     stripos($publisher, 'GraphicAudio') !== false)) {
                    return true;
                }
            }
        }
        
        return false;
    }

    /**
     * Format authors for directory paths
     */
    protected function formatAuthorsForDirectory(array $authors): string
    {
        $normalizedAuthors = array_map([$this, 'normalizeAuthorName'], $authors);
        return implode(' & ', $normalizedAuthors);
    }

    /**
     * Normalize author names for directory use
     */
    protected function normalizeAuthorName(string $authorName): string
    {
        $name = trim($authorName);
        
        $name = preg_replace('/\b([A-Z])\s+/', '$1. ', $name);
        $name = preg_replace('/\s+([A-Z])$/', ' $1.', $name);
        $name = preg_replace('/\b([A-Z]\.)\s+([A-Z]\.)/', '$1$2', $name);
        $name = preg_replace('/\b([A-Z]\.)\s+([A-Z]\.)/', '$1$2', $name);
        
        return trim($name);
    }

    /**
     * Download cover image
     */
    public function downloadCoverImage(string $imageUrl, string $directoryPath, string $source = 'unknown'): ?string
    {
        try {
            $imageData = file_get_contents($imageUrl);
            if (!$imageData) {
                return null;
            }

            $extension = $this->getImageExtensionFromUrl($imageUrl);
            $filename = "cover_{$source}.{$extension}";
            $filePath = "{$directoryPath}/{$filename}";

            if (file_put_contents($filePath, $imageData)) {
                // Set file permissions for cover image
                chmod($filePath, 0664);
                return $filename;
            }

        } catch (\Exception $e) {
            Log::warning("Failed to download cover image: " . $e->getMessage(), [
                'url' => $imageUrl,
                'directory' => $directoryPath
            ]);
        }

        return null;
    }

    /**
     * Get image extension from URL
     */
    protected function getImageExtensionFromUrl(string $url): string
    {
        $extension = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION);
        
        if (in_array(strtolower($extension), ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
            return strtolower($extension);
        }
        
        return 'jpg';
    }

    /**
     * Find existing author directory
     */
    public function findExistingAuthorDirectory(array $authors, string $seriesName = null): ?string
    {
        if (empty($authors)) {
            return null;
        }

        $bookStoragePath = config('filesystems.disks.books.root') ?? env('BOOK_STORAGE_PATH');
        if (!$bookStoragePath || !File::isDirectory($bookStoragePath)) {
            return null;
        }

        $normalizedAuthors = array_map([$this, 'normalizeAuthorName'], $authors);
        
        $authorCombinations = [];
        
        if (count($normalizedAuthors) > 1) {
            $authorCombinations[] = $normalizedAuthors;
            $authorCombinations[] = array_reverse($normalizedAuthors);
            
            if (count($normalizedAuthors) >= 3) {
                $authorCombinations[] = [$normalizedAuthors[0], $normalizedAuthors[1]];
                $authorCombinations[] = [$normalizedAuthors[1], $normalizedAuthors[0]];
            }
        }
        
        if (count($normalizedAuthors) === 1) {
            $authorCombinations[] = $normalizedAuthors;
        }

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($bookStoragePath, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $dir) {
                if (!$dir->isDir()) {
                    continue;
                }

                $dirPath = $dir->getPathname();
                $pathParts = explode('/', str_replace($bookStoragePath . '/', '', $dirPath));
                
                if (count($pathParts) >= 2) {
                    $authorDirName = $pathParts[1];
                    
                    foreach ($authorCombinations as $combination) {
                        $expectedDirName = $this->formatAuthorsForDirectory($combination);
                        
                        if ($authorDirName === $expectedDirName) {
                            if ($seriesName && count($pathParts) >= 3) {
                                $seriesDirName = $pathParts[2];
                                if (stripos($seriesDirName, $seriesName) !== false) {
                                    return $authorDirName;
                                }
                            } else {
                                return $authorDirName;
                            }
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            Log::warning("Error searching for existing author directories: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Handle directory conflict resolution
     */
    public function handleDirectoryConflict(array $audiobook, string $targetDir): string
    {
        $originalTargetDir = $targetDir;
        $counter = 1;
        
        while (File::isDirectory($targetDir)) {
            $targetDir = "{$originalTargetDir}_" . str_pad($counter, 2, '0', STR_PAD_LEFT);
            $counter++;
            
            if ($counter > 99) {
                break;
            }
        }
        
        return $targetDir;
    }

    /**
     * Flatten CD directories by moving files up one level
     */
    public function flattenCdDirectories(string $sourcePath): void
    {
        if (!File::isDirectory($sourcePath)) {
            return;
        }

        $directories = File::directories($sourcePath);
        $hasCdDirectories = false;
        
        foreach ($directories as $dir) {
            $dirName = basename($dir);
            if (preg_match('/^(cd|disc|disk)\s*\d+$/i', $dirName)) {
                $hasCdDirectories = true;
                break;
            }
        }
        
        if (!$hasCdDirectories) {
            return;
        }

        foreach ($directories as $dir) {
            $dirName = basename($dir);
            if (preg_match('/^(cd|disc|disk)\s*(\d+)$/i', $dirName, $matches)) {
                $cdNumber = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
                $files = File::files($dir);
                
                foreach ($files as $file) {
                    $filename = $file->getFilename();
                    $extension = $file->getExtension();
                    $baseName = pathinfo($filename, PATHINFO_FILENAME);
                    
                    $newFilename = "{$cdNumber}-{$baseName}.{$extension}";
                    $newPath = "{$sourcePath}/{$newFilename}";
                    
                    File::move($file->getPathname(), $newPath);
                }
                
                File::deleteDirectory($dir);
            }
        }
    }

    /**
     * Check if directory is empty
     */
    public function isDirectoryEmpty(string $path): bool
    {
        if (!File::isDirectory($path)) {
            return true;
        }
        
        $files = File::allFiles($path);
        $directories = File::directories($path);
        
        return empty($files) && empty($directories);
    }

    /**
     * Clean up source directory after successful import
     */
    public function cleanupSourceDirectory(array $audiobook, bool $filesAlreadyExist = false): void
    {
        $sourcePath = $audiobook['path'];
        
        if (!File::isDirectory($sourcePath)) {
            return;
        }

        try {
            if ($this->isDirectoryEmpty($sourcePath)) {
                File::deleteDirectory($sourcePath);
                Log::info("Cleaned up empty source directory: {$sourcePath}");
            }
        } catch (\Exception $e) {
            Log::warning("Failed to cleanup source directory: " . $e->getMessage(), [
                'path' => $sourcePath
            ]);
        }
    }

    /**
     * Calculate audio information from files with advanced tag extraction
     */
    protected function calculateAudioInfo(array $audioFiles): array
    {
        $totalDuration = 0;
        $allTags = [];
        $audioExtensions = ['mp3', 'm4a', 'm4b', 'flac', 'ogg', 'wma', 'aac'];
        $audioFileCount = 0;
        
        foreach ($audioFiles as $filePath) {
            $extension = strtolower(pathinfo(is_string($filePath) ? $filePath : $filePath['path'], PATHINFO_EXTENSION));
            
            if (in_array($extension, $audioExtensions)) {
                $audioFileCount++;
                
                try {
                    // Handle both string paths and file arrays
                    $file = is_string($filePath) ? $filePath : $filePath['path'];
                    $fileName = basename($file);
                    
                    // If we have pre-calculated data, use it
                    if (is_array($filePath) && isset($filePath['duration'])) {
                        $totalDuration += (int)$filePath['duration'];
                        if (isset($filePath['tags'])) {
                            $allTags[$fileName] = $filePath['tags'];
                        }
                    } else {
                        // Try to get duration from file directly
                        $fileDuration = $this->getAudioFileDuration($file);
                        if ($fileDuration > 0) {
                            $totalDuration += $fileDuration;
                            $allTags[$fileName] = ['calculated_duration' => $fileDuration];
                        }
                    }
                } catch (\Exception $e) {
                    Log::warning("Failed to calculate audio info for {$file}: " . $e->getMessage());
                }
            }
        }
        
        return [
            'count' => $audioFileCount,
            'duration' => $totalDuration, // in seconds
            'tags' => $allTags
        ];
    }
    
    /**
     * Get duration of audio file in seconds
     */
    protected function getAudioFileDuration(string $filePath): int
    {
        try {
            // Try ffprobe first (most reliable)
            $output = shell_exec("ffprobe -i " . escapeshellarg($filePath) . " -show_entries format=duration -v quiet -of csv=\"p=0\"");
            if ($output && is_numeric(trim($output))) {
                return (int)round(floatval(trim($output)));
            }
            
            // Fallback to file modification patterns
            return 0;
        } catch (\Exception $e) {
            Log::warning("Failed to get audio file duration: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Get author's preferred genre based on existing books
     */
    public function getAuthorPreferredGenre($authorData): ?string
    {
        if (empty($authorData)) {
            return null;
        }
        
        // Handle both string and array author data
        $authorNames = is_array($authorData) ? $authorData : [$authorData];
        
        foreach ($authorNames as $authorName) {
            $authorName = trim($authorName);
            if (empty($authorName)) {
                continue;
            }
            
            // Find the author in the database
            $author = Author::where('name', $authorName)->first();
            if (!$author) {
                continue;
            }
            
            // Get genre distribution for this author's books
            $genreStats = DB::table('books')
                ->join('author_book', 'books.id', '=', 'author_book.book_id')
                ->join('book_genre', 'books.id', '=', 'book_genre.book_id')
                ->join('genres', 'book_genre.genre_id', '=', 'genres.id')
                ->where('author_book.author_id', $author->id)
                ->select('genres.name', DB::raw('COUNT(*) as count'))
                ->groupBy('genres.name')
                ->orderByDesc('count')
                ->first();
            
            if ($genreStats && $genreStats->count >= 2) {
                // If author has 2+ books in the same genre, use that genre
                return $genreStats->name;
            }
        }
        
        return null;
    }

    /**
     * Clean series name by removing author names
     */
    protected function cleanSeriesName(string $seriesName, array $authors): string
    {
        $cleanedSeries = $seriesName;
        
        foreach ($authors as $author) {
            $authorWords = explode(' ', trim($author));
            foreach ($authorWords as $word) {
                if (strlen($word) > 2) { // Only remove meaningful words, not initials
                    $cleanedSeries = preg_replace('/\b' . preg_quote($word, '/') . '\b/i', '', $cleanedSeries);
                }
            }
        }
        
        // Clean up extra spaces and common series words
        $cleanedSeries = preg_replace('/\b(series|saga|chronicles|collection)\b/i', '', $cleanedSeries);
        $cleanedSeries = preg_replace('/\s+/', ' ', $cleanedSeries);
        $cleanedSeries = trim($cleanedSeries, ' -,');
        
        return $cleanedSeries ?: $seriesName; // Return original if cleaning resulted in empty string
    }

    /**
     * Add GraphicAudio marker if detected from source or metadata
     */
    public function addGraphicAudioMarker(string $title, array $metadata): string
    {
        // Check if GraphicAudio marker is already present
        if (preg_match('/\(Graphic\s*Audio\)/i', $title)) {
            return preg_replace('/\(Graphic\s*Audio\)/i', '(GraphicAudio)', $title);
        }
        
        // Check various fields for GraphicAudio indicators
        $sourcePath = $metadata['source_path'] ?? '';
        $narrator = $metadata['narrator'] ?? '';
        $publisher = $metadata['publisher'] ?? '';
        
        $isGraphicAudio = false;
        
        // Check source path
        if (stripos($sourcePath, 'graphic') !== false && stripos($sourcePath, 'audio') !== false) {
            $isGraphicAudio = true;
        }
        
        // Check narrator field
        if (is_array($narrator)) {
            foreach ($narrator as $n) {
                if (stripos($n, 'graphic') !== false && stripos($n, 'audio') !== false) {
                    $isGraphicAudio = true;
                    break;
                }
            }
        } elseif (is_string($narrator) && stripos($narrator, 'graphic') !== false && stripos($narrator, 'audio') !== false) {
            $isGraphicAudio = true;
        }
        
        // Check publisher field
        if (is_array($publisher)) {
            foreach ($publisher as $p) {
                if (stripos($p, 'graphic') !== false && stripos($p, 'audio') !== false) {
                    $isGraphicAudio = true;
                    break;
                }
            }
        } elseif (is_string($publisher) && stripos($publisher, 'graphic') !== false && stripos($publisher, 'audio') !== false) {
            $isGraphicAudio = true;
        }
        
        if ($isGraphicAudio && !preg_match('/\(GraphicAudio\)/i', $title)) {
            return $title . ' (GraphicAudio)';
        }
        
        return $title;
    }
}