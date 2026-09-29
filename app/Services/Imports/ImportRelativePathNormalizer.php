<?php

declare(strict_types=1);

namespace App\Services\Imports;

use Normalizer;

/**
 * Normalizes client-supplied manifest paths. Paths are always relative to the
 * selected source; anything that could escape it is rejected, never repaired.
 */
class ImportRelativePathNormalizer
{
    public function normalize(string $relativePath): string
    {
        $reason = $this->rejectionReason($relativePath);
        if ($reason !== null) {
            throw ImportApiException::validation(
                'A file path in this import is not allowed.',
                ['relative_path' => $relativePath, 'reason' => $reason]
            );
        }

        $normalized = Normalizer::normalize($relativePath, Normalizer::FORM_C);

        return $normalized === false ? $relativePath : $normalized;
    }

    public function key(string $normalizedPath): string
    {
        return mb_strtolower($normalizedPath, 'UTF-8');
    }

    private function rejectionReason(string $path): ?string
    {
        if ($path === '' || !mb_check_encoding($path, 'UTF-8')) {
            return 'empty_or_invalid_encoding';
        }
        if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path) === 1) {
            return 'absolute_path';
        }
        if (str_contains($path, '\\')) {
            return 'backslash_separator';
        }
        if (preg_match('/\p{Cc}/u', $path) === 1) {
            return 'control_character';
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return 'invalid_segment';
            }
        }

        return null;
    }
}
