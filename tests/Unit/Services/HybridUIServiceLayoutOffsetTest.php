<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\HybridUIService;
use Tests\TestCase;

class HybridUIServiceLayoutOffsetTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function promptCursorIsTwoRowsBelowFooterSeparator(): void
    {
        $service = new HybridUIService();
        $reflection = new \ReflectionClass(HybridUIService::class);

        $heightProp = $reflection->getProperty('height');
        $heightProp->setAccessible(true);
        $heightProp->setValue($service, 40);

        $footerSeparatorMethod = $reflection->getMethod('getFooterSeparatorY');
        $footerSeparatorMethod->setAccessible(true);
        $footerSeparatorY = (int) $footerSeparatorMethod->invoke($service);

        $promptCursorMethod = $reflection->getMethod('getPromptCursorY');
        $promptCursorMethod->setAccessible(true);
        $promptCursorY = (int) $promptCursorMethod->invoke($service);

        $this->assertSame($footerSeparatorY + 2, $promptCursorY);
    }
}
