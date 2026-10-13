<?php

declare(strict_types=1);

namespace App\Support;

/**
 * UI strings for JavaScript: lang/<fallback>.json overlaid with lang/<current>.json.
 * Keys are the English source text, as with PHP's __().
 */
class JsTranslations
{
    /**
     * @return array<string, string>
     */
    public static function forCurrentLocale(): array
    {
        $strings = [];
        foreach (array_unique([(string) config('app.fallback_locale'), app()->getLocale()]) as $locale) {
            $strings = array_merge($strings, self::read($locale));
        }

        return $strings;
    }

    /**
     * @return array<string, string>
     */
    private static function read(string $locale): array
    {
        $path = lang_path($locale . '.json');
        if (!is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? array_map('strval', $decoded) : [];
    }
}
