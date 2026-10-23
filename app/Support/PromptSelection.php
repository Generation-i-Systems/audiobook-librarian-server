<?php

declare(strict_types=1);

namespace App\Support;

final class PromptSelection
{
    /**
     * Map a Laravel Prompts select/search response back to the option key.
     *
     * Prompts returns the selected label when the options array is a list (keys
     * '0','1',... become ints 0,1,...) and the key otherwise; callers always want the key.
     *
     * @param array<int|string, string> $options
     */
    public static function keyFor(array $options, int|string|null $response): string
    {
        if ($response === null) {
            return '';
        }

        if (!array_is_list($options) && array_key_exists($response, $options)) {
            return (string) $response;
        }

        $key = array_search((string) $response, $options, true);

        return $key === false ? '' : (string) $key;
    }
}
