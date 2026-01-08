<?php

namespace Tests\Import\Unit\Services;

use App\Services\ImportUIService;
use Tests\TestCase;

class ImportUIServiceGridSelectionTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function moveGridSelectionMovesAcrossColumnsAndRows(): void
    {
        $reflection = new \ReflectionClass(ImportUIService::class);
        $method = $reflection->getMethod('moveGridSelection');
        $method->setAccessible(true);

        // 8 items, 3 columns => rows=3 (column-major)
        // indices: col0: 0,1,2 | col1: 3,4,5 | col2: 6,7

        $this->assertSame(3, $method->invoke(null, 0, 'right', 3, 3, 8));
        $this->assertSame(4, $method->invoke(null, 1, 'right', 3, 3, 8));
        $this->assertSame(5, $method->invoke(null, 2, 'right', 3, 3, 8));

        // Moving right from index 5 (col1,row2) -> candidate 8 invalid, should fall back to nearest valid in col2 (index 7)
        $this->assertSame(7, $method->invoke(null, 5, 'right', 3, 3, 8));

        // Moving left from index 6 (col2,row0) -> index 3
        $this->assertSame(3, $method->invoke(null, 6, 'left', 3, 3, 8));

        // Up/down within a column
        $this->assertSame(1, $method->invoke(null, 2, 'up', 3, 3, 8));
        $this->assertSame(2, $method->invoke(null, 1, 'down', 3, 3, 8));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function resolveDefaultSelectionIndexHonorsProvidedDefault(): void
    {
        $reflection = new \ReflectionClass(ImportUIService::class);
        $method = $reflection->getMethod('resolveDefaultSelectionIndex');
        $method->setAccessible(true);

        $keys = ['1', '2', '3'];

        $this->assertSame(1, $method->invoke(null, $keys, '2'));
        $this->assertSame(0, $method->invoke(null, $keys, ''));
        $this->assertSame(0, $method->invoke(null, $keys, '999'));
    }
}
