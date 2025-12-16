<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class BookEnrichmentService
{
    protected ?AudibleService $audibleService = null;

    public function __construct()
    {
        // Services will be injected lazily
    }

    /**
     * Extract series number from title and clean the title
     */
    public function extractSeriesNumberFromTitle(array &$metadata): void
    {
        if (empty($metadata['title'])) {
            return;
        }

        $title = trim($metadata['title']);
        $title = $this->stripTrailingTitleQualifiers($title);

        if (preg_match('/^(.*?)\s*\[\s*([^\]]+)\s*\]\s*$/', $title, $matches)) {
            $possibleTitle = trim($matches[1]);
            $bracketContent = trim($matches[2]);
            $bracketContent = $this->stripTrailingTitleQualifiers($bracketContent);

            if (
                preg_match(
                    '/^(.*?)\s*(?:#|book|volume|vol\.?|part)\s*([\d.]+)\s*$/i',
                    $bracketContent,
                    $bracketMatches
                )
            ) {
                $seriesName = trim($bracketMatches[1]);
                $seriesNumber = $this->parseSeriesNumber($bracketMatches[2]);

                if ($seriesName !== '' && empty($metadata['series'])) {
                    $metadata['series'] = $seriesName;
                }
                if ($seriesNumber !== null && empty($metadata['series_number'])) {
                    $metadata['series_number'] = $seriesNumber;
                }

                if ($possibleTitle !== '') {
                    $title = $possibleTitle;
                }
            }
        }

        $patterns = [
            '/^(.+?),\s*Book\s+([\d.]+)$/i',            // "Title, Book 1"
            '/^(.+?)\s+Book\s+([\d.]+)$/i',             // "Title Book 1"
            '/^(.+?),\s*Volume\s+([\d.]+)$/i',          // "Title, Volume 1"
            '/^(.+?)\s+Volume\s+([\d.]+)$/i',           // "Title Volume 1"
            '/^(.+?),\s*#([\d.]+)$/i',                  // "Title, #1"
            '/^(.+?)\s+#([\d.]+)$/i',                   // "Title #1"
            '/^(.+?),\s*Part\s+([\d.]+)$/i',            // "Title, Part 1"
            '/^(.+?)\s+Part\s+([\d.]+)$/i',             // "Title Part 1"
            '/^(.+?)\s+([\d.]+)$/',                     // "Title 1" (last resort)
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $title, $matches)) {
                $cleanTitle = $this->stripTrailingTitleQualifiers(trim($matches[1]));
                $bookNumber = $this->parseSeriesNumber($matches[2]);

                $metadata['title'] = $cleanTitle;
                if (empty($metadata['series_number'])) {
                    $metadata['series_number'] = $bookNumber;
                }

                return;
            }
        }

        $metadata['title'] = $title;
    }

    private function parseSeriesNumber(string $value): int|float|null
    {
        $trimmed = trim($value);
        if ($trimmed === '' || !is_numeric($trimmed)) {
            return null;
        }

        if (str_contains($trimmed, '.')) {
            return (float) $trimmed;
        }

        return (int) $trimmed;
    }

    private function stripTrailingTitleQualifiers(string $title): string
    {
        $current = trim($title);
        $patterns = [
            '/\s*\((?:unabridged|abridged|complete|full\s*cast|dramati[sz]ed\s*adaptation|enhanced|remastered|special\s*edition|revised\s*edition|anniversary\s*edition)\)\s*$/i',
            '/\s*\[(?:unabridged|abridged|complete|full\s*cast|dramati[sz]ed\s*adaptation|enhanced|remastered|special\s*edition|revised\s*edition|anniversary\s*edition)\]\s*$/i',
        ];

        do {
            $previous = $current;
            foreach ($patterns as $pattern) {
                $current = preg_replace($pattern, '', $current);
                $current = trim($current);
            }
        } while ($current !== $previous);

        return $current;
    }

    /**
     * Enrich metadata with external data sources
     */
    public function enrichWithExternalData(array $metadata, array $options = []): array
    {
        if (empty($metadata['title']) || empty($metadata['author'])) {
            return [];
        }

        $enrichedData = [];
        $authorName = is_array($metadata['author']) ? $metadata['author'][0] : $metadata['author'];

        // CRITICAL: Normalize author before sending to enrichment services
        // Extract actual author from patterns like "Graphic Audio [Alex Archer]"
        $authorName = $this->normalizeAuthorForEnrichment($authorName);

        // If author is invalid (e.g., just "Graphic Audio"), skip enrichment
        if (empty($authorName)) {
            return [];
        }
        $sources = $options['sources'] ?? ['audible', 'google_books'];
        $maxRetries = $options['max_retries'] ?? 3;

        $missingFields = $this->getMissingDataFields($enrichedData);
        if (empty($missingFields)) {
            return $enrichedData;
        }

        foreach ($sources as $source) {
            $sourceData = $this->searchFromSource($source, $metadata['title'], $authorName, $maxRetries);
            if ($sourceData) {
                $enrichedData = array_merge($enrichedData, $sourceData);
            }

            $missingFields = $this->getMissingDataFields($enrichedData);
            if (empty($missingFields)) {
                break;
            }
        }

        return $enrichedData;
    }

    /**
     * Search from a specific source
     */
    protected function searchFromSource(string $source, string $title, string $author, int $maxRetries = 3): ?array
    {
        return match ($source) {
            'audible' => $this->retryApiCall(fn () => $this->searchAudible($title, $author), 'Audible', '', $maxRetries),
            'google_books' => $this->retryApiCall(fn () => $this->searchGoogleBooks($title, $author), 'Google Books', '', $maxRetries),
            default => null
        };
    }

    /**
     * Retry API calls with exponential backoff
     */
    protected function retryApiCall(callable $apiCall, string $serviceName, string $description = '', int $maxRetries = 3): mixed
    {
        $attempt = 1;

        while ($attempt <= $maxRetries) {
            try {
                return $apiCall();
            } catch (\Exception $e) {
                if ($attempt === $maxRetries) {
                    Log::error("{$serviceName}: All {$maxRetries} attempts failed - " . $e->getMessage());
                    return null;
                }

                $delay = pow(2, $attempt - 1);
                sleep($delay);
                $attempt++;
            }
        }

        return null;
    }

    /**
     * Get list of missing data fields that we should continue searching for
     */
    protected function getMissingDataFields(array $enrichedData): array
    {
        $missing = [];

        if (empty($enrichedData['cover_url'])) {
            $missing[] = 'cover image';
        }

        if (empty($enrichedData['description'])) {
            $missing[] = 'description';
        }

        return $missing;
    }

    /**
     * Search Audible for book data using AudibleService
     */
    protected function searchAudible(string $title, string $author): ?array
    {
        try {
            if (!$this->audibleService) {
                $this->audibleService = app(AudibleService::class);
            }

            $results = $this->audibleService->searchBooksWithFiltering($title, $author, ['limit' => 1]);

            if (!empty($results) && isset($results[0])) {
                $bookData = $results[0];

                $enrichedData = [];

                $enrichedData['audible_raw'] = $bookData;

                if (!empty($bookData['description'])) {
                    $enrichedData['description'] = $this->cleanDescription($bookData['description']);
                }

                if (!empty($bookData['coverImageUrl'])) {
                    $enrichedData['cover_url'] = $bookData['coverImageUrl'];
                }

                if (!empty($bookData['publishDate'])) {
                    $year = date('Y', strtotime($bookData['publishDate']));
                    if ($year && $year > 1800) {
                        $enrichedData['year'] = (int) $year;
                    }
                }

                if (!empty($bookData['publisher'])) {
                    $enrichedData['publisher'] = $bookData['publisher'];
                }

                if (!empty($bookData['series'])) {
                    $enrichedData['series'] = $bookData['series'];
                }

                // Extract genre/categories if available
                if (!empty($bookData['genre'])) {
                    $enrichedData['genre'] = is_array($bookData['genre']) ? $bookData['genre'] : [$bookData['genre']];
                } elseif (!empty($bookData['categories'])) {
                    $enrichedData['genre'] = is_array($bookData['categories']) ? $bookData['categories'] : [$bookData['categories']];
                }

                return $enrichedData;
            }
        } catch (\Exception $e) {
            Log::warning("Audible search failed: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Search Google Books for book data
     */
    protected function searchGoogleBooks(string $title, string $author): ?array
    {
        try {
            $query = urlencode($title . ' ' . $author);
            $url = "https://www.googleapis.com/books/v1/volumes?q={$query}&maxResults=1";

            $response = file_get_contents($url);
            if (!$response) {
                return null;
            }

            $data = json_decode($response, true);
            if (empty($data['items'][0])) {
                return null;
            }

            $book = $data['items'][0];
            $volumeInfo = $book['volumeInfo'] ?? [];

            $enrichedData = [];
            $enrichedData['google_books_raw'] = $book;

            if (!empty($volumeInfo['description'])) {
                $enrichedData['description'] = $this->cleanDescription($volumeInfo['description']);
            }

            if (!empty($volumeInfo['imageLinks']['large'])) {
                $enrichedData['cover_url'] = $volumeInfo['imageLinks']['large'];
            } elseif (!empty($volumeInfo['imageLinks']['medium'])) {
                $enrichedData['cover_url'] = $volumeInfo['imageLinks']['medium'];
            } elseif (!empty($volumeInfo['imageLinks']['thumbnail'])) {
                $enrichedData['cover_url'] = str_replace('zoom=1', 'zoom=2', $volumeInfo['imageLinks']['thumbnail']);
            }

            if (!empty($volumeInfo['publishedDate'])) {
                $year = date('Y', strtotime($volumeInfo['publishedDate']));
                if ($year && $year > 1800) {
                    $enrichedData['year'] = (int) $year;
                }
            }

            if (!empty($volumeInfo['publisher'])) {
                $enrichedData['publisher'] = $volumeInfo['publisher'];
            }

            // Extract genre/categories if available
            if (!empty($volumeInfo['categories'])) {
                $enrichedData['genre'] = is_array($volumeInfo['categories']) ? $volumeInfo['categories'] : [$volumeInfo['categories']];
            }

            return $enrichedData;
        } catch (\Exception $e) {
            Log::warning("Google Books search failed: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Clean description text
     */
    protected function cleanDescription(string $description): string
    {
        $description = strip_tags($description);
        $description = html_entity_decode($description, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $description = preg_replace('/\s+/', ' ', $description);
        return trim($description);
    }

    /**
     * Validate that enrichment data is valid and safe to merge
     */
    public function isValidEnrichment(array $originalMetadata, array $enrichedData): bool
    {
        if (empty($enrichedData)) {
            return false;
        }

        // Validate title consistency if both exist
        if (!empty($originalMetadata['title']) && !empty($enrichedData['title'])) {
            $originalTitle = strtolower(trim($originalMetadata['title']));
            $enrichedTitle = strtolower(trim($enrichedData['title']));

            if (strlen($originalTitle) > 3 && strlen($enrichedTitle) > 3) {
                $similarity = similar_text($originalTitle, $enrichedTitle);
                $maxLength = max(strlen($originalTitle), strlen($enrichedTitle));
                $similarityPercentage = ($similarity / $maxLength) * 100;

                if ($similarityPercentage < 50) {
                    return false;
                }
            }
        }

        // Validate author consistency if both exist
        if (!empty($originalMetadata['author']) && !empty($enrichedData['author'])) {
            $originalAuthors = is_array($originalMetadata['author']) ? $originalMetadata['author'] : [$originalMetadata['author']];
            $enrichedAuthors = is_array($enrichedData['author']) ? $enrichedData['author'] : [$enrichedData['author']];

            $hasMatchingAuthor = false;
            foreach ($originalAuthors as $originalAuthor) {
                foreach ($enrichedAuthors as $enrichedAuthor) {
                    if (
                        stripos($originalAuthor, $enrichedAuthor) !== false ||
                        stripos($enrichedAuthor, $originalAuthor) !== false
                    ) {
                        $hasMatchingAuthor = true;
                        break 2;
                    }
                }
            }

            if (!$hasMatchingAuthor) {
                return false;
            }
        }

        // Check for reasonable data values
        if (isset($enrichedData['year']) && is_numeric($enrichedData['year'])) {
            $year = (int) $enrichedData['year'];
            if ($year < 1800 || $year > date('Y') + 2) {
                return false;
            }
        }

        // Validate cover URL format if present
        if (!empty($enrichedData['cover_url'])) {
            if (!filter_var($enrichedData['cover_url'], FILTER_VALIDATE_URL)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if metadata contains enrichment data from external sources
     */
    public function hasEnrichmentData(array $metadata): bool
    {
        $enrichmentFields = [
            'audible_raw',
            'google_books_raw',
            'audiobook_bay_raw',
            'cover_url',
        ];

        foreach ($enrichmentFields as $field) {
            if (!empty($metadata[$field])) {
                return true;
            }
        }

        if (!empty($metadata['description']) && strlen($metadata['description']) > 100) {
            return true;
        }

        return false;
    }

    /**
     * Detect multi-book pattern in title
     */
    public function detectMultiBookPattern(string $title): ?array
    {
        $patterns = [
            'books' => '/^(.+?)\s*books?\s*(\d+)\s*[-–]\s*(\d+)$/i',
            'parts' => '/^(.+?)\s*parts?\s*(\d+)\s*[-–]\s*(\d+)$/i',
            'volumes' => '/^(.+?)\s*volumes?\s*(\d+)\s*[-–]\s*(\d+)$/i',
            'episodes' => '/^(.+?)\s*episodes?\s*(\d+)\s*[-–]\s*(\d+)$/i',
        ];

        foreach ($patterns as $type => $pattern) {
            if (preg_match($pattern, trim($title), $matches)) {
                return [
                    'type' => $type,
                    'series_name' => trim($matches[1]),
                    'start_number' => (int) $matches[2],
                    'end_number' => (int) $matches[3],
                    'count' => (int) $matches[3] - (int) $matches[2] + 1
                ];
            }
        }

        return null;
    }

    /**
     * Extract book title from filename
     */
    public function extractBookTitleFromFilename(string $filename, string $seriesName, int $bookNumber): string
    {
        $baseName = pathinfo($filename, PATHINFO_FILENAME);

        $patterns = [
            "/^{$seriesName}\s*[-–]\s*book\s*{$bookNumber}\s*[-–]\s*(.+)$/i",
            "/^{$seriesName}\s*{$bookNumber}\s*[-–]\s*(.+)$/i",
            "/^(.+?)\s*[-–]\s*{$seriesName}\s*{$bookNumber}$/i",
            "/^(.+?)\s*[-–]\s*book\s*{$bookNumber}$/i",
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $baseName, $matches)) {
                return trim($matches[1]);
            }
        }

        return "{$seriesName} {$bookNumber}";
    }

    /**
     * Clean series name
     */
    public function cleanSeriesName(string $seriesName, array $authors): string
    {
        $cleanName = trim($seriesName);

        foreach ($authors as $author) {
            $authorLastName = explode(' ', trim($author));
            $authorLastName = end($authorLastName);

            if (stripos($cleanName, $authorLastName) !== false) {
                $cleanName = trim(str_ireplace($authorLastName, '', $cleanName));
                $cleanName = preg_replace('/^[-–\s]+|[-–\s]+$/', '', $cleanName);
                break;
            }
        }

        $cleanName = preg_replace('/\s*[-–]\s*books?\s*\d+\s*[-–]\s*\d+\s*$/i', '', $cleanName);
        $cleanName = preg_replace('/\s*[-–]\s*(books?|parts?|volumes?)\s*$/i', '', $cleanName);

        return trim($cleanName);
    }

    /**
     * Add GraphicAudio marker to title if detected
     */
    public function addGraphicAudioMarker(string $title, array $metadata): string
    {
        $sourcePath = $metadata['source_path'] ?? '';

        if (
            stripos($sourcePath, 'graphicaudio') !== false ||
            (isset($metadata['narrator']) && stripos($metadata['narrator'], 'full cast') !== false)
        ) {
            if (stripos($title, 'GraphicAudio') === false) {
                return $title . ' (GraphicAudio)';
            }
        }

        return $title;
    }

    /**
     * Normalize author name for enrichment - extract actual author from patterns
     * Examples:
     *   "Graphic Audio [Alex Archer]" -> "Alex Archer"
     *   "GraphicAudio [John Smith]" -> "John Smith"
     *
     * CRITICAL: Author will NEVER contain "Graphic" AND "Audio" - this is always invalid
     */
    protected function normalizeAuthorForEnrichment(string $authorName): string
    {
        $name = trim($authorName);

        // Pattern: "Publisher/Narrator [Actual Author]"
        if (preg_match('/^.+?\s*\[([^\]]+)\]$/', $name, $matches)) {
            $name = trim($matches[1]);
        }

        // CRITICAL: If author contains both "Graphic" and "Audio", it's INVALID
        // This should NEVER be sent to enrichment - it's a narrator/publisher
        if (stripos($name, 'graphic') !== false && stripos($name, 'audio') !== false) {
            return '';
        }

        // If it's just "Full Cast", return empty (narrator, not author)
        if (preg_match('/^Full\s*Cast$/i', $name)) {
            return '';
        }

        return trim($name);
    }
}
