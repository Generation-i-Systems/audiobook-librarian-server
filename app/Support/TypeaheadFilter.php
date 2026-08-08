<?php

declare(strict_types=1);

namespace App\Support;

final class TypeaheadFilter
{
    /**
     * Filter key => label options down to those whose label contains $query
     * (case-insensitive). Matches are ordered by how early $query appears in
     * the label, with alphabetical order as a tiebreaker.
     *
     * @param array<array-key, string> $options
     * @return array<array-key, string>
     */
    public static function filter(array $options, string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return $options;
        }

        $matches = [];
        foreach ($options as $key => $label) {
            $position = stripos((string) $label, $query);
            if ($position !== false) {
                $matches[] = ['key' => $key, 'label' => (string) $label, 'position' => $position];
            }
        }

        usort($matches, function (array $a, array $b): int {
            return $a['position'] <=> $b['position'] ?: strcasecmp($a['label'], $b['label']);
        });

        $result = [];
        foreach ($matches as $match) {
            $result[$match['key']] = $match['label'];
        }

        return $result;
    }
}
