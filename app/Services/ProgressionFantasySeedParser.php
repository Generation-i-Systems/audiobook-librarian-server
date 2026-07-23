<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Deterministic (non-AI) parser for the "Progression Fantasy Collection Seed" import batch.
 *
 * Folder naming: "[series] - [author] - [narrator]"
 * File naming:   "[series] - Book [number]{ - [title]}" (also accepts Books/Side Story/Novel,
 *                a leading numeric ordering prefix, and combined numbers like "1+2" or "3, 4").
 */
class ProgressionFantasySeedParser
{
    /**
     * @return array{series: string, author: array<int, string>, narrator: array<int, string>}
     */
    public function parseDirectoryName(string $dirName): array
    {
        $normalized = trim(preg_replace('/\s+/', ' ', $dirName) ?? $dirName);
        $segments = preg_split('/\s-\s/', $normalized) ?: [$normalized];

        if (count($segments) < 3) {
            return [
                'series' => $normalized,
                'author' => [],
                'narrator' => [],
            ];
        }

        $series = trim($segments[0]);
        $narrator = trim($segments[count($segments) - 1]);
        $author = implode(' - ', array_slice($segments, 1, count($segments) - 2));

        return [
            'series' => $series,
            'author' => $this->splitList($author),
            'narrator' => $this->splitList($narrator),
        ];
    }

    /**
     * @return array{number: ?string, title: string}|null null if the filename doesn't match any
     *   recognized pattern.
     */
    public function parseFileName(string $fileName, string $series): ?array
    {
        $name = preg_replace('/\.[^.]+$/', '', $fileName) ?? $fileName;
        $name = preg_replace('/^\d{1,3}(?:-\d{1,3})?\s*-\s*/', '', $name) ?? $name;

        $pattern = '/^(?:(?<seriesPrefix>.+?)\s*-\s*)?(?<keyword>Books?|Side Story|Novel)\s+'
            . '(?<number>\d+(?:\.\d+)?(?:\s*[-+,]\s*\d+(?:\.\d+)?)*)\s*(?:-\s*(?<title>.+))?$/i';

        if (!preg_match($pattern, trim($name), $matches)) {
            return null;
        }

        $cleanedNumber = $this->cleanNumber($matches['number']);
        $isSideStory = strcasecmp(trim($matches['keyword']), 'Side Story') === 0;
        $number = $isSideStory ? ('00.' . $cleanedNumber) : $cleanedNumber;

        $title = trim($matches['title'] ?? '');
        if ($title === '') {
            $title = trim($series . ' ' . $cleanedNumber);
        }

        return [
            'number' => $number,
            'title' => $title,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function splitList(string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $value)), fn ($v) => $v !== ''));
    }

    private function cleanNumber(string $raw): string
    {
        preg_match_all('/\d+(?:\.\d+)?|[-+,]/', $raw, $tokenMatches);

        $parts = [];
        foreach ($tokenMatches[0] as $token) {
            if ($token === ',') {
                $parts[] = ', ';
            } elseif (preg_match('/^\d/', $token)) {
                $parts[] = $this->cleanNumberToken($token);
            } else {
                $parts[] = $token;
            }
        }

        return implode('', $parts);
    }

    private function cleanNumberToken(string $token): string
    {
        if (str_contains($token, '.')) {
            [$intPart, $decPart] = explode('.', $token, 2);
            return $this->stripLeadingZeros($intPart) . '.' . $decPart;
        }

        return $this->stripLeadingZeros($token);
    }

    private function stripLeadingZeros(string $digits): string
    {
        $stripped = ltrim($digits, '0');
        return $stripped === '' ? '0' : $stripped;
    }
}
