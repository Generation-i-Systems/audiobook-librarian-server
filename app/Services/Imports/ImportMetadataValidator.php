<?php

declare(strict_types=1);

namespace App\Services\Imports;

/**
 * Validates contract `Metadata` objects from review edits and approvals.
 *
 * Values are rejected, never rewritten: what the user confirmed must be stored
 * exactly as sent (see "Data Confirmation Integrity" in CLAUDE.md).
 */
class ImportMetadataValidator
{
    private const LIST_LIMITS = [
        'authors' => [20, 255],
        'narrators' => [20, 255],
        'genres' => [10, 100],
        'tags' => [50, 100],
    ];

    private const MAX_DIRECTORY_LENGTH = 500;

    private const FIELDS = [
        'title', 'authors', 'narrators', 'series', 'genres', 'tags', 'language', 'description', 'year', 'cover_artifact_id',
    ];

    /**
     * A JSON Merge Patch for metadata: any subset of fields; null clears a nullable field.
     *
     * @param mixed $patch
     * @return array<string, mixed>
     */
    public function validatePatch(mixed $patch): array
    {
        $patch = $this->assertObject($patch, 'metadata');
        $errors = $this->fieldErrors($patch, false);
        $this->throwIfErrors($errors);

        return $patch;
    }

    /**
     * The result of applying a patch must still be coherent (e.g. a series number alone
     * cannot create a series without a name).
     *
     * @param array<string, mixed> $metadata
     */
    public function assertMergedValid(array $metadata): void
    {
        $seriesError = $this->seriesError($metadata['series'] ?? null, true);
        if ($seriesError !== null) {
            $this->throwIfErrors(['metadata.series' => $seriesError]);
        }
    }

    /**
     * Complete metadata for an approval. A title and at least one author are required.
     *
     * @param mixed $metadata
     * @return array<string, mixed>
     */
    public function validateApproved(mixed $metadata): array
    {
        $metadata = $this->assertObject($metadata, 'metadata');
        $errors = $this->fieldErrors($metadata, true);
        if (!array_key_exists('title', $metadata)) {
            $errors['metadata.title'] = 'A title is required.';
        }
        if (($metadata['authors'] ?? []) === []) {
            $errors['metadata.authors'] = 'At least one author is required.';
        }
        $this->throwIfErrors($errors);

        return $metadata;
    }

    /**
     * Apply a validated merge patch. Arrays replace; the series object merges.
     *
     * @param array<string, mixed> $current
     * @param array<string, mixed> $patch
     * @return array<string, mixed>
     */
    public function merge(array $current, array $patch): array
    {
        foreach ($patch as $field => $value) {
            if ($field === 'series' && is_array($value) && is_array($current['series'] ?? null)) {
                $value = array_merge($current['series'], $value);
            }
            $current[$field] = $value;
        }

        return $current;
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, string>
     */
    private function fieldErrors(array $metadata, bool $approval): array
    {
        $errors = [];
        foreach (array_diff(array_keys($metadata), self::FIELDS) as $unknown) {
            $errors['metadata.' . $unknown] = 'This field cannot be set.';
        }

        if (array_key_exists('title', $metadata) && !$this->isText($metadata['title'], 500)) {
            $errors['metadata.title'] = 'The title must be non-empty text of at most 500 characters.';
        }
        foreach (self::LIST_LIMITS as $field => [$maxItems, $maxLength]) {
            if (array_key_exists($field, $metadata) && !$this->isTextList($metadata[$field], $maxItems, $maxLength)) {
                $errors['metadata.' . $field] = "Each entry must be non-empty text of at most $maxLength characters "
                    . "(up to $maxItems entries).";
            }
        }
        if (array_key_exists('series', $metadata)) {
            $seriesError = $this->seriesError($metadata['series'], $approval);
            if ($seriesError !== null) {
                $errors['metadata.series'] = $seriesError;
            }
        }
        if (
            array_key_exists('language', $metadata) && $metadata['language'] !== null
            && !$this->isText($metadata['language'], 32)
        ) {
            $errors['metadata.language'] = 'The language must be text of at most 32 characters.';
        }
        if (array_key_exists('year', $metadata) && $metadata['year'] !== null && !$this->isYear($metadata['year'])) {
            $errors['metadata.year'] = 'The year must be a four-digit number or null.';
        }
        if (
            array_key_exists('cover_artifact_id', $metadata) && $metadata['cover_artifact_id'] !== null
            && !$this->isText($metadata['cover_artifact_id'], 128)
        ) {
            $errors['metadata.cover_artifact_id'] = 'The cover must be an artifact id or null.';
        }
        if (
            array_key_exists('description', $metadata) && $metadata['description'] !== null
            && !(is_string($metadata['description']) && mb_strlen($metadata['description']) <= 20000)
        ) {
            $errors['metadata.description'] = 'The description must be text of at most 20000 characters.';
        }

        return $errors;
    }

    private function seriesError(mixed $series, bool $approval): ?string
    {
        if ($series === null) {
            return null;
        }
        if (!is_array($series) || array_is_list($series) && $series !== []) {
            return 'The series must be an object with a name and number.';
        }
        if (array_diff(array_keys($series), ['name', 'number']) !== []) {
            return 'The series only accepts a name and number.';
        }
        $name = $series['name'] ?? null;
        if (($approval || array_key_exists('name', $series)) && !$this->isText($name, 255)) {
            return 'The series name must be non-empty text of at most 255 characters.';
        }
        $number = $series['number'] ?? null;
        if ($number !== null && (!(is_int($number) || is_float($number)) || $number < 0)) {
            return 'The series number must be a non-negative number or null.';
        }

        return null;
    }

    private function isYear(mixed $value): bool
    {
        return is_int($value) && $value >= 1000 && $value <= 9999;
    }

    /**
     * A destination folder typed by the person, relative to the book root. Anything unsafe is rejected rather
     * than rewritten, so the folder that is stored is exactly the one that was confirmed.
     *
     * @return string|null the folder, or null to clear a previously typed one
     */
    public function validateCustomDirectory(mixed $directory): ?string
    {
        if ($directory === null) {
            return null;
        }
        $valid = is_string($directory)
            && mb_strlen($directory) <= self::MAX_DIRECTORY_LENGTH
            && trim($directory) === $directory
            && $directory !== ''
            && !str_starts_with($directory, '/')
            && !str_ends_with($directory, '/')
            && !str_contains($directory, '\\')
            && preg_match('/[\x00-\x1F\x7F]/', $directory) !== 1;
        if ($valid) {
            foreach (explode('/', (string) $directory) as $segment) {
                if ($segment === '' || $segment === '.' || $segment === '..' || trim($segment) !== $segment) {
                    $valid = false;
                    break;
                }
            }
        }
        if (!$valid) {
            throw ImportApiException::validation('The destination folder was not valid.', [
                'fields' => ['custom_directory' => 'Use a folder inside the library, like Genre/Author/Title.'],
            ]);
        }

        return (string) $directory;
    }

    private function isText(mixed $value, int $maxLength): bool
    {
        return is_string($value) && trim($value) !== '' && mb_strlen($value) <= $maxLength;
    }

    private function isTextList(mixed $value, int $maxItems, int $maxLength): bool
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > $maxItems) {
            return false;
        }
        foreach ($value as $item) {
            if (!$this->isText($item, $maxLength)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function assertObject(mixed $value, string $field): array
    {
        if (!is_array($value) || (array_is_list($value) && $value !== [])) {
            throw ImportApiException::validation('The book details were not valid.', [
                'fields' => [$field => 'Must be an object.'],
            ]);
        }

        return $value;
    }

    /**
     * @param array<string, string> $errors
     */
    private function throwIfErrors(array $errors): void
    {
        if ($errors !== []) {
            throw ImportApiException::validation('The book details were not valid.', ['fields' => $errors]);
        }
    }
}
