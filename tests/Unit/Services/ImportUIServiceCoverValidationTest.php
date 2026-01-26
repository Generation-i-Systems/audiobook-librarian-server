<?php

namespace Tests\Unit\Services;

use App\Services\ImportUIService;
use Tests\TestCase;

class ImportUIServiceCoverValidationTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function isSupportedImageDataReturnsTrueForKnownMagicBytes(): void
    {
        $ui = new ImportUIService();

        $reflection = new \ReflectionClass($ui);
        $method = $reflection->getMethod('isSupportedImageData');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($ui, "\xFF\xD8\xFF" . str_repeat("\0", 20)));
        $this->assertTrue($method->invoke($ui, "\x89PNG\r\n\x1A\n" . str_repeat("\0", 20)));
        $this->assertTrue($method->invoke($ui, 'GIF89a' . str_repeat("\0", 20)));
        $this->assertTrue($method->invoke($ui, 'RIFF' . str_repeat("\0", 4) . 'WEBP' . str_repeat("\0", 20)));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function isSupportedImageDataReturnsFalseForEmptyOrUnknownData(): void
    {
        $ui = new ImportUIService();

        $reflection = new \ReflectionClass($ui);
        $method = $reflection->getMethod('isSupportedImageData');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($ui, ''));
        $this->assertFalse($method->invoke($ui, '<html>not an image</html>'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function cacheCoverForCurrentBookClearsCacheWhenCoverMissing(): void
    {
        $ui = new ImportUIService();
        $reflection = new \ReflectionClass($ui);

        $tempFile = tempnam(sys_get_temp_dir(), 'cover_cache_test_');
        $this->assertIsString($tempFile);
        file_put_contents($tempFile, 'dummy');

        $setProperty = static function (string $name, $value) use ($reflection, $ui): void {
            $property = $reflection->getProperty($name);
            $property->setAccessible(true);
            $property->setValue($ui, $value);
        };

        $getProperty = static function (string $name) use ($reflection, $ui) {
            $property = $reflection->getProperty($name);
            $property->setAccessible(true);
            return $property->getValue($ui);
        };

        $setProperty('cachedCoverTempFile', $tempFile);
        $setProperty('cachedCoverUrl', 'https://example.com/cover.jpg');
        $setProperty('renderedCoverUrl', 'https://example.com/cover.jpg');
        $setProperty('renderedCoverSignature', 'signature');
        $setProperty('currentBook', ['title' => 'Test Book', 'cover_url' => null]);

        $method = $reflection->getMethod('cacheCoverForCurrentBook');
        $method->setAccessible(true);
        $method->invoke($ui);

        $this->assertFileDoesNotExist($tempFile);
        $this->assertNull($getProperty('cachedCoverTempFile'));
        $this->assertNull($getProperty('cachedCoverUrl'));
        $this->assertNull($getProperty('renderedCoverUrl'));
        $this->assertNull($getProperty('renderedCoverSignature'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function cacheCoverForCurrentBookClearingInvokesInlineRendererReset(): void
    {
        $ui = new ImportUIServiceClearInlineStub();

        $reflection = new \ReflectionClass($ui);
        $setProperty = static function (string $name, $value) use ($reflection, $ui): void {
            $property = $reflection->getProperty($name);
            $property->setAccessible(true);
            $property->setValue($ui, $value);
        };

        $tempFile = tempnam(sys_get_temp_dir(), 'cover_cache_test_');
        $this->assertIsString($tempFile);
        file_put_contents($tempFile, 'dummy');

        $setProperty('cachedCoverTempFile', $tempFile);
        $setProperty('cachedCoverUrl', 'https://example.com/cover.jpg');
        $setProperty('currentBook', ['title' => 'Test Book', 'cover_url' => null]);

        $method = $reflection->getMethod('cacheCoverForCurrentBook');
        $method->setAccessible(true);
        $method->invoke($ui);

        $this->assertTrue($ui->wasInlineCoverCleared());
    }
}

class ImportUIServiceClearInlineStub extends ImportUIService
{
    private bool $inlineCleared = false;

    protected function clearInlineCoverRendering(): void
    {
        $this->inlineCleared = true;
    }

    public function table(array $headers, array $rows): void
    {
    }

    public function wasInlineCoverCleared(): bool
    {
        return $this->inlineCleared;
    }
}
