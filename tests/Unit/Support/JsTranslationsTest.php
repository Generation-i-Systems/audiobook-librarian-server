<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\JsTranslations;
use Tests\TestCase;

class JsTranslationsTest extends TestCase
{
    private string $langPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->langPath = sys_get_temp_dir() . '/lang-' . uniqid();
        mkdir($this->langPath);
        app()->useLangPath($this->langPath);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->langPath . '/*.json') ?: []);
        rmdir($this->langPath);
        parent::tearDown();
    }

    public function testReturnsTheFallbackStringsOverlaidWithTheCurrentLocale(): void
    {
        file_put_contents($this->langPath . '/en.json', json_encode(['Save' => 'Save', 'Cancel' => 'Cancel']));
        file_put_contents($this->langPath . '/es.json', json_encode(['Save' => 'Guardar']));
        config(['app.fallback_locale' => 'en']);
        app()->setLocale('es');

        $this->assertSame(['Save' => 'Guardar', 'Cancel' => 'Cancel'], JsTranslations::forCurrentLocale());
    }

    public function testMissingOrMalformedFilesYieldAnEmptyList(): void
    {
        file_put_contents($this->langPath . '/es.json', '{not json');
        config(['app.fallback_locale' => 'en']);
        app()->setLocale('es');

        $this->assertSame([], JsTranslations::forCurrentLocale());
    }
}
