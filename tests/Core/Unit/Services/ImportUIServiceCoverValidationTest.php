<?php

namespace Tests\Core\Unit\Services;

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
}
