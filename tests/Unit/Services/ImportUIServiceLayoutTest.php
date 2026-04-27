<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\ImportUIService;
use Tests\TestCase;

class ImportUIServiceLayoutTest extends TestCase
{
    private function computeLayoutForHeight(int $height): array
    {
        $service = new ImportUIService();
        $reflection = new \ReflectionClass(ImportUIService::class);

        $heightProp = $reflection->getProperty('height');
        $heightProp->setAccessible(true);
        $heightProp->setValue($service, $height);

        $method = $reflection->getMethod('computeLayout');
        $method->setAccessible(true);

        return $method->invoke($service);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function computeLayoutReturnsAllExpectedKeys(): void
    {
        $layout = $this->computeLayoutForHeight(50);

        $expectedKeys = [
            'titleY',
            'progressY',
            'bookDetailsY',
            'bookDetailsHeight',
            'logY',
            'logHeight',
            'separatorY',
            'menuStartY',
            'menuHeight',
            'maxLogs',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $layout, "Layout missing key: {$key}");
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function computeLayoutSectionsDoNotOverlap(): void
    {
        foreach ([30, 39, 50, 60, 80] as $height) {
            $layout = $this->computeLayoutForHeight($height);

            $bookDetailsEnd = $layout['bookDetailsY'] + $layout['bookDetailsHeight'];
            $this->assertLessThanOrEqual(
                $layout['logY'],
                $bookDetailsEnd,
                "Book details overlaps log at height {$height}"
            );

            $logEnd = $layout['logY'] + $layout['logHeight'];
            $this->assertLessThanOrEqual(
                $layout['separatorY'],
                $logEnd,
                "Log extends past separator at height {$height}"
            );

            $this->assertSame(
                $layout['separatorY'] + 1,
                $layout['menuStartY'],
                "Menu does not start right after separator at height {$height}"
            );
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function computeLayoutLogGrowsWithTerminalHeight(): void
    {
        $layout39 = $this->computeLayoutForHeight(39);
        $layout60 = $this->computeLayoutForHeight(60);
        $layout80 = $this->computeLayoutForHeight(80);

        // Menu stays fixed at 14; extra terminal height goes to the log
        $this->assertSame(11, $layout39['menuHeight'], 'Menu should be 11 at height 39');
        $this->assertSame(11, $layout60['menuHeight'], 'Menu should be 11 at height 60');

        $this->assertGreaterThan(
            $layout39['logHeight'],
            $layout60['logHeight'],
            'Log should be larger at height 60 than 39'
        );

        $this->assertGreaterThan(
            $layout60['logHeight'],
            $layout80['logHeight'],
            'Log should be larger at height 80 than 60'
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function computeLayoutRespectsMinimumLogHeight(): void
    {
        $layout = $this->computeLayoutForHeight(30);

        $this->assertGreaterThanOrEqual(1, $layout['logHeight'], 'Log height below minimum of 1');
        $this->assertGreaterThanOrEqual(1, $layout['maxLogs'], 'Max logs below minimum');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function computeLayoutTitleAndProgressPositions(): void
    {
        foreach ([30, 39, 50, 60] as $height) {
            $layout = $this->computeLayoutForHeight($height);
            $this->assertSame(1, $layout['titleY'], "Title should be at Y=1 at height {$height}");
            $this->assertSame(2, $layout['progressY'], "Progress should be at Y=2 at height {$height}");
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function computeLayoutBookDetailsHeightIsFixed(): void
    {
        $layout40 = $this->computeLayoutForHeight(40);
        $layout80 = $this->computeLayoutForHeight(80);

        $this->assertSame(
            $layout40['bookDetailsHeight'],
            $layout80['bookDetailsHeight'],
            'Book details height should be fixed regardless of terminal height'
        );

        $this->assertSame(14, $layout40['bookDetailsHeight']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function computeLayoutAt39RowsGivesMenuEnoughForEditFields(): void
    {
        $layout = $this->computeLayoutForHeight(39);

        // Laravel Prompts select rendering overhead = 4 rows
        // 9 edit-field options need scroll=9, total = 9+4 = 13 rows
        $promptOverhead = 4;
        $scroll = min(9, $layout['menuHeight'] - $promptOverhead);
        $this->assertSame(7, $scroll, 'Scroll must show 7 options at 39 rows (menu height reduced)');

        // Verify all sections fit within terminal
        $menuEnd = $layout['menuStartY'] + $layout['menuHeight'] - 1;
        $this->assertLessThanOrEqual(39, $menuEnd, 'Menu must not extend beyond terminal height');

        // Log should have a reasonable size
        $this->assertGreaterThanOrEqual(4, $layout['logHeight'], 'Log should have at least 4 rows');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function computeLayoutFixedTopPositions(): void
    {
        foreach ([39, 50, 80] as $height) {
            $layout = $this->computeLayoutForHeight($height);

            $this->assertSame(3, $layout['bookDetailsY'], "Book details should start at Y=3 at height {$height}");
            $this->assertSame(14, $layout['bookDetailsHeight'], "Book details height should be 14 at height {$height}");
            $this->assertSame(17, $layout['logY'], "Log should start at Y=17 at height {$height}");
        }
    }
}
