<?php

namespace App\Services;

use App\Traits\GenreMapping;
use Illuminate\Support\Facades\Log;

class BookEnrichmentService
{
    use GenreMapping;

    protected ?AudibleService $audibleService = null;
    protected ?GoogleBooksApiService $googleBooksService = null;
    protected ?HardcoverService $hardcoverService = null;

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
            '/\s*\((?:unabridged|abridged|complete|full\s*cast|dramati[sz]ed\s*adaptation|enhanced|remastered|' .
            'special\s*edition|revised\s*edition|anniversary\s*edition)\)\s*$/i',
            '/\s*\[(?:unabridged|abridged|complete|full\s*cast|dramati[sz]ed\s*adaptation|enhanced|remastered|' .
            'special\s*edition|revised\s*edition|anniversary\s*edition)\]\s*$/i',
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
        $enrichmentResults = [];
        $genreBySource = [];
        $authorName = is_array($metadata['author']) ? $metadata['author'][0] : $metadata['author'];

        // CRITICAL: Normalize author before sending to enrichment services
        // Extract actual author from patterns like "Graphic Audio [Alex Archer]"
        $authorName = $this->normalizeAuthorForEnrichment($authorName);
        $authorName = $this->normalizeAuthorForExternalLookup($authorName);

        // If author is invalid (e.g., just "Graphic Audio"), skip enrichment
        if (empty($authorName)) {
            return [];
        }
        $sources = $options['sources'] ?? ['audible', 'google_books', 'hardcover'];
        $maxRetries = $options['max_retries'] ?? 3;
        $forceEnrichment = $options['force'] ?? false;

        // If we already have all critical fields AND a strong genre, skip enrichment (unless forced)
        $weakGenres = ['Other', 'Unknown', 'Classic', 'General Fiction', 'Action'];
        $genre = $metadata['genre'] ?? null;
        $genreStr = is_array($genre) ? ($genre[0] ?? '') : (string) ($genre ?? '');
        $hasGenre = $genre !== null && $genre !== '' && (!is_array($genre) || count($genre) > 0)
            && !in_array(trim($genreStr), $weakGenres, true);
        if (!$forceEnrichment && $this->hasCriticalMetadata($metadata) && $hasGenre) {
            return [];
        }

        foreach ($sources as $source) {
            $sourceData = $this->searchFromSource($source, $metadata['title'], $authorName, $maxRetries);
            if ($sourceData) {
                $enrichedData = $this->mergeFillMissing($enrichedData, $sourceData, $metadata, $forceEnrichment);
                $enrichmentResults[$source] = 'success';
                $sourceGenre = $sourceData['genre'] ?? null;
                $genreBySource[$source] = $sourceGenre ? (is_array($sourceGenre) ? implode(', ', $sourceGenre) : $sourceGenre) : null;
            } else {
                $enrichmentResults[$source] = 'no_data';
                $genreBySource[$source] = null;
            }

            // After each source, stop only if we have all critical fields, a strong unambiguous genre
            if (!$forceEnrichment) {
                $combined = array_merge($metadata, $enrichedData);
                $currentGenre = $enrichedData['genre'] ?? $metadata['genre'] ?? null;
                $genreIsAmbiguous = is_array($currentGenre) && count($currentGenre) > 1;
                $currentGenreStr = is_array($currentGenre) ? ($currentGenre[0] ?? '') : (string) ($currentGenre ?? '');
                $hasStrongGenre = $currentGenre !== null && $currentGenre !== ''
                    && !in_array(trim($currentGenreStr), $weakGenres, true);
                if ($this->hasCriticalMetadata($combined) && $hasStrongGenre && !$genreIsAmbiguous) {
                    break;
                }
            }
        }

        if (!empty($enrichmentResults)) {
            $enrichedData['_enrichment_results'] = $enrichmentResults;
        }
        if (!empty($genreBySource)) {
            $enrichedData['_genre_by_source'] = $genreBySource;
        }

        return $enrichedData;
    }

    protected function hasCover(array $metadata): bool
    {
        return !empty($metadata['cover_data'])
            || !empty($metadata['cover_image'])
            || !empty($metadata['cover_path'])
            || !empty($metadata['cover_url']);
    }

    protected function hasCriticalMetadata(array $metadata): bool
    {
        $hasTitle = isset($metadata['title']) && is_string($metadata['title']) && trim($metadata['title']) !== '';
        $authors = $metadata['author'] ?? [];
        $hasAuthor = (is_array($authors) && count($authors) > 0)
            || (is_string($authors) && trim($authors) !== '');
        $hasDescription = isset($metadata['description']) && is_string($metadata['description'])
            && trim($metadata['description']) !== '';

        return $hasTitle && $hasAuthor && $hasDescription && $this->hasCover($metadata);
    }

    protected function mergeFillMissing(array $current, array $incoming, array $original, bool $force = false): array
    {
        $result = $current;
        $weakGenres = ['Other', 'Unknown', 'Classic', 'General Fiction', 'Action'];

        foreach ($incoming as $key => $value) {
            if ($value === null) {
                continue;
            }

            // When forced, include all enrichment data for comparison
            if ($force) {
                $result[$key] = $value;
                continue;
            }

            // Do not replace existing original metadata
            // For arrays (like genre), also check if they're empty
            $originalValue = $original[$key] ?? null;
            $hasOriginalValue = $originalValue !== null && $originalValue !== '';
            if (is_array($originalValue)) {
                $hasOriginalValue = count($originalValue) > 0;
            }

            // Weak AI-assigned genres should be overrideable by enrichment sources
            if ($key === 'genre' && $hasOriginalValue) {
                $genreStr = is_array($originalValue) ? ($originalValue[0] ?? '') : (string) $originalValue;
                if (in_array(trim($genreStr), $weakGenres, true)) {
                    $hasOriginalValue = false;
                }
            }

            if (array_key_exists($key, $original) && $hasOriginalValue) {
                continue;
            }

            // Do not override an existing cover if we already have one
            if (in_array($key, ['cover_url', 'cover_image', 'cover_path', 'cover_data'], true) && $this->hasCover($original)) {
                continue;
            }

            // For genre: if we already have a multi-value list and an incoming source provides
            // a more specific single value that is a subset of the current list, prefer it.
            if ($key === 'genre' && isset($result[$key])) {
                $currentGenre = is_array($result[$key]) ? $result[$key] : [$result[$key]];
                $incomingGenre = is_array($value) ? $value : [$value];
                if (count($currentGenre) > count($incomingGenre)) {
                    $isSubset = count(array_diff($incomingGenre, $currentGenre)) === 0;
                    if ($isSubset) {
                        $result[$key] = $value;
                        continue;
                    }
                }
            }

            // Only fill if not already present in result
            if (!array_key_exists($key, $result) || $result[$key] === null || $result[$key] === '') {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    protected function normalizeAuthorForExternalLookup(string $authorName): string
    {
        $value = trim($authorName);
        if ($value === '') {
            return '';
        }

        $parts = preg_split('/\s*(?:,|&|\band\b)\s*/i', $value);
        $primary = $parts[0] ?? $value;

        return trim($primary);
    }

    /**
     * Search from a specific source
     */
    protected function searchFromSource(string $source, string $title, string $author, int $maxRetries = 3): ?array
    {
        return match ($source) {
            'audible' => $this->retryApiCall(
                fn () => $this->searchAudible($title, $author),
                'Audible',
                '',
                $maxRetries
            ),
            'google_books' => $this->retryApiCall(
                fn () => $this->searchGoogleBooks($title, $author),
                'Google Books',
                '',
                $maxRetries
            ),
            'hardcover' => $this->retryApiCall(
                fn () => $this->searchHardcover($title, $author),
                'Hardcover',
                '',
                $maxRetries
            ),
            default => null
        };
    }

    protected function searchHardcover(string $title, string $author): ?array
    {
        try {
            if (!$this->hardcoverService) {
                $this->hardcoverService = app(HardcoverService::class);
            }

            $result = $this->hardcoverService->searchAndMerge([
                'title' => $title,
                'authors' => [$author],
            ]);

            if (!$result) {
                return null;
            }

            $enrichedData = [];

            if (!empty($result['description'])) {
                $enrichedData['description'] = $this->cleanDescription($result['description']);
            }

            if (!empty($result['coverImage'])) {
                $enrichedData['cover_url'] = $result['coverImage'];
            }

            if (!empty($result['releaseDate'])) {
                $year = date('Y', strtotime($result['releaseDate']));
                if ($year > 1800) {
                    $enrichedData['year'] = (int) $year;
                }
            }

            if (!empty($result['isbn_13'])) {
                $enrichedData['isbn'] = $result['isbn_13'];
            } elseif (!empty($result['isbn_10'])) {
                $enrichedData['isbn'] = $result['isbn_10'];
            }

            if (!empty($result['publisher'])) {
                $enrichedData['publisher'] = $result['publisher'];
            }

            if (!empty($result['genres']) && is_array($result['genres'])) {
                $enrichedData['genre'] = $this->mapToValidGenreList(
                    $this->normalizeGenreList($result['genres'])
                );
            }

            return empty($enrichedData) ? null : $enrichedData;
        } catch (\Exception $e) {
            Log::warning("Hardcover search failed: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Retry API calls with exponential backoff
     */
    protected function retryApiCall(
        callable $apiCall,
        string $serviceName,
        string $description = '',
        int $maxRetries = 3
    ): mixed {
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

        $genre = $enrichedData['genre'] ?? null;
        $weakGenres = ['Other', 'Unknown', 'Classic', 'General Fiction', 'Action'];
        $isMissingGenre = $genre === null
            || $genre === ''
            || (is_array($genre) && count($genre) === 0)
            || (!is_array($genre) && is_string($genre) && in_array(trim($genre), $weakGenres, true));
        if ($isMissingGenre) {
            $missing[] = 'genre';
        }

        $narrator = $enrichedData['narrator'] ?? null;
        $isMissingNarrator = $narrator === null
            || $narrator === ''
            || (is_array($narrator) && count($narrator) === 0);
        if ($isMissingNarrator) {
            $missing[] = 'narrator';
        }

        return $missing;
    }

    protected function normalizeGenreList(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        $items = is_array($value) ? $value : [$value];
        $result = [];

        foreach ($items as $item) {
            if ($item === null) {
                continue;
            }

            if (is_string($item)) {
                $trimmed = trim($item);
                if ($trimmed !== '') {
                    $result[] = $trimmed;
                }
                continue;
            }

            if (is_array($item)) {
                $candidateKeys = ['name', 'label', 'title', 'fullPath', 'path'];
                foreach ($candidateKeys as $key) {
                    if (!empty($item[$key]) && is_string($item[$key])) {
                        $trimmed = trim($item[$key]);
                        if ($trimmed !== '') {
                            $result[] = $trimmed;
                        }
                        continue 2;
                    }
                }

                continue;
            }
        }

        $result = array_values(array_unique($result));

        return $result;
    }

    protected function mapToValidGenreList(array $genres): array
    {
        // Known compound labels that represent two distinct genres
        $compoundExpansions = [
            'science fiction & fantasy' => ['Science Fiction', 'Fantasy'],
            'mystery, thriller & suspense' => ['Action'],
        ];

        $result = [];
        foreach ($genres as $genre) {
            if (!is_string($genre)) {
                continue;
            }

            $trimmed = trim($genre);
            if ($trimmed === '') {
                continue;
            }

            $lower = strtolower($trimmed);
            if (isset($compoundExpansions[$lower])) {
                foreach ($compoundExpansions[$lower] as $expanded) {
                    $result[] = $expanded;
                }
                continue;
            }

            $result[] = $this->mapToValidGenre($trimmed);
        }

        $result = array_values(array_unique($result));
        if (empty($result)) {
            return [];
        }

        return $result;
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

            $results = $this->audibleService->searchBooksWithFiltering($title, $author, [
                'limit' => 1,
            ]);

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

                if (!empty($bookData['publishedYear']) && is_numeric($bookData['publishedYear'])) {
                    $year = (int) $bookData['publishedYear'];
                    if ($year > 1800) {
                        $enrichedData['year'] = $year;
                    }
                } elseif (!empty($bookData['publishDate'])) {
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

                if (!empty($bookData['narratorsList'])) {
                    if (is_array($bookData['narratorsList'])) {
                        $enrichedData['narrator'] = $bookData['narratorsList'];
                    } else {
                        $enrichedData['narrator'] = [$bookData['narratorsList']];
                    }
                } elseif (!empty($bookData['narrator'])) {
                    if (is_array($bookData['narrator'])) {
                        $enrichedData['narrator'] = $bookData['narrator'];
                    } else {
                        $enrichedData['narrator'] = [$bookData['narrator']];
                    }
                }

                // Extract genre/categories if available; discard if everything maps to weak genres
                $weakGenres = ['General Fiction', 'Action', 'Other', 'Unknown'];
                $rawCategorySource = $bookData['genre'] ?? $bookData['categories'] ?? $bookData['category'] ?? null;
                if (!empty($rawCategorySource)) {
                    $mapped = $this->mapToValidGenreList($this->normalizeGenreList($rawCategorySource));
                    $strongMapped = array_values(array_filter($mapped, fn ($g) => !in_array($g, $weakGenres, true)));
                    if (!empty($strongMapped)) {
                        $enrichedData['genre'] = $mapped;
                    }
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
            if (!$this->googleBooksService) {
                $this->googleBooksService = app(GoogleBooksApiService::class);
            }

            $result = $this->googleBooksService->searchAndMerge([
                'title' => $title,
                'author' => $author,
            ]);

            if (!$result) {
                return null;
            }

            $enrichedData = [];

            if (!empty($result['description'])) {
                $enrichedData['description'] = $this->cleanDescription($result['description']);
            }

            if (!empty($result['coverImageUrl'])) {
                $enrichedData['cover_url'] = $result['coverImageUrl'];
            }

            if (!empty($result['releaseDate'])) {
                $year = date('Y', strtotime($result['releaseDate']));
                if ($year && $year > 1800) {
                    $enrichedData['year'] = (int) $year;
                }
            }

            if (!empty($result['publisher'])) {
                $enrichedData['publisher'] = $result['publisher'];
            }

            // categories are already hierarchically split by GoogleBooksApiService::splitCategories
            if (!empty($result['categories']) && is_array($result['categories'])) {
                $weakGenres = ['General Fiction', 'Action', 'Other', 'Unknown'];
                $mapped = $this->mapToValidGenreList($this->normalizeGenreList($result['categories']));
                $strongMapped = array_values(array_filter($mapped, fn ($g) => !in_array($g, $weakGenres, true)));
                if (!empty($strongMapped)) {
                    $enrichedData['genre'] = $strongMapped;
                }
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
        $description = html_entity_decode(
            $description,
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );
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
            if (is_array($originalMetadata['author'])) {
                $originalAuthors = $originalMetadata['author'];
            } else {
                $originalAuthors = [$originalMetadata['author']];
            }
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
     * Perform manual enrichment based on a specific selection (e.g. from Google Books)
     */
    public function manualSelectionWithComparison(
        array $metadata,
        array $manualSelection,
        callable $tableCallback,
        callable $formatBytesCallback
    ): array {
        $enrichedData = $manualSelection;

        $headers = ['Field', 'Current', 'Enriched'];
        $rows = [];

        $fields = [
            'title' => 'Title',
            'author' => 'Author',
            'narrator' => 'Narrator',
            'series' => 'Series',
            'series_number' => 'Series #',
            'year' => 'Year',
            'genre' => 'Genre',
            'publisher' => 'Publisher',
        ];

        foreach ($fields as $key => $label) {
            $current = $metadata[$key] ?? '';
            $enriched = $enrichedData[$key] ?? '';

            if (is_array($current)) {
                $current = implode(', ', $current);
            }
            if (is_array($enriched)) {
                $enriched = implode(', ', $enriched);
            }

            if ($current !== $enriched && !empty($enriched)) {
                $rows[] = [$label, (string) $current, (string) $enriched];
            }
        }

        if (!empty($rows)) {
            $tableCallback($headers, $rows);
        }

        return array_merge($metadata, $enrichedData);
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
