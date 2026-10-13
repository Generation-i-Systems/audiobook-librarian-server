<?php

declare(strict_types=1);

namespace Tests\Unit\Localization;

use Tests\TestCase;

class SpanishCatalogTest extends TestCase
{
    public function testSpanishIsASupportedLocale(): void
    {
        $this->assertSame('Español', config('locales.supported.es'));
    }

    public function testEverySourceStringHasASpanishTranslationWithMatchingPlaceholders(): void
    {
        $catalog = json_decode((string) file_get_contents(base_path('lang/es.json')), true, 512, JSON_THROW_ON_ERROR);
        $keys = $this->literalKeysUsedInSource();
        $missing = array_values(array_filter($keys, static fn (string $key): bool => !isset($catalog[$key])));

        // A few untranslated proper nouns / identical-by-design strings are allowed.
        $this->assertLessThanOrEqual(0, count(array_diff($missing, $this->untranslatedByDesign())), implode("\n", $missing));

        foreach ($catalog as $key => $value) {
            preg_match_all('/:[a-z_]+/i', $key, $expected);
            preg_match_all('/:[a-z_]+/i', $value, $actual);
            $this->assertEqualsCanonicalizing($expected[0], $actual[0], "Placeholders differ for: {$key}");
        }
    }

    public function testSpanishFrameworkFilesDefineTheSameKeysAsEnglish(): void
    {
        foreach (['auth', 'pagination', 'passwords', 'validation'] as $group) {
            $english = require base_path("lang/en/{$group}.php");
            $spanish = require base_path("lang/es/{$group}.php");
            $this->assertSame(array_keys($english), array_keys($spanish), $group);
        }
    }

    /**
     * @return list<string>
     */
    private function untranslatedByDesign(): array
    {
        return ['Audiobook Librarian', 'ID', 'PG', 'PG-13', 'LibriVox ↗', 'Emoji:'];
    }

    /**
     * @return list<string>
     */
    private function literalKeysUsedInSource(): array
    {
        $keys = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('resources/views')));
        foreach ($files as $file) {
            if (!str_ends_with($file->getFilename(), '.blade.php') || str_contains($file->getPathname(), '/docs/')) {
                continue;
            }
            if (preg_match_all("/(?:\\b__|window\\.__)\\(\\s*'((?:[^'\\\\\\\\]|\\\\\\\\.)*)'/", (string) file_get_contents($file->getPathname()), $m)) {
                foreach ($m[1] as $key) {
                    $key = str_replace("\\'", "'", $key);
                    if (preg_match('/[A-Za-z]{2}/', $key) && $key !== ':name') {
                        $keys[$key] = $key;
                    }
                }
            }
        }

        return array_values($keys);
    }
}
