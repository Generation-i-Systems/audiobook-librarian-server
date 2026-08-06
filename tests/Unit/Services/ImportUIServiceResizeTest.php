<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\ImportUIService;
use Tests\TestCase;

class ImportUIServiceResizeTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function resizeUpdatesWidthAndHeightInPlainMode(): void
    {
        $service = new ImportUIService();
        $service->setPlainMode(true);

        $service->resize(100, 30);

        $reflection = new \ReflectionClass(ImportUIService::class);
        $widthProp = $reflection->getProperty('width');
        $widthProp->setAccessible(true);
        $heightProp = $reflection->getProperty('height');
        $heightProp->setAccessible(true);

        $this->assertSame(100, $widthProp->getValue($service));
        $this->assertSame(30, $heightProp->getValue($service));
    }
}
