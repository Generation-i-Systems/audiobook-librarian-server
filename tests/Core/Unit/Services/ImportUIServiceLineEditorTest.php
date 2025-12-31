<?php

namespace Tests\Core\Unit\Services;

use App\Services\ImportUIService;
use Tests\TestCase;

class ImportUIServiceLineEditorTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function applyLineEditorActionInsertsCharactersAndMovesCursor(): void
    {
        $reflection = new \ReflectionClass(ImportUIService::class);
        $method = $reflection->getMethod('applyLineEditorAction');
        $method->setAccessible(true);

        $state = ['buffer' => 'abc', 'cursor' => 3];
        $state = $method->invoke(null, $state, ['left']);
        $this->assertSame(['buffer' => 'abc', 'cursor' => 2], $state);

        $state = $method->invoke(null, $state, ['char', 'X']);
        $this->assertSame(['buffer' => 'abXc', 'cursor' => 3], $state);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function applyLineEditorActionBackspaceAndDeleteWork(): void
    {
        $reflection = new \ReflectionClass(ImportUIService::class);
        $method = $reflection->getMethod('applyLineEditorAction');
        $method->setAccessible(true);

        $state = ['buffer' => 'abcd', 'cursor' => 2];

        $state = $method->invoke(null, $state, ['backspace']);
        $this->assertSame(['buffer' => 'acd', 'cursor' => 1], $state);

        $state = $method->invoke(null, $state, ['delete']);
        $this->assertSame(['buffer' => 'ad', 'cursor' => 1], $state);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function applyLineEditorActionHomeEndClampCursor(): void
    {
        $reflection = new \ReflectionClass(ImportUIService::class);
        $method = $reflection->getMethod('applyLineEditorAction');
        $method->setAccessible(true);

        $state = ['buffer' => 'abcd', 'cursor' => 999];
        $state = $method->invoke(null, $state, ['home']);
        $this->assertSame(['buffer' => 'abcd', 'cursor' => 0], $state);

        $state = $method->invoke(null, $state, ['end']);
        $this->assertSame(['buffer' => 'abcd', 'cursor' => 4], $state);

        $state = $method->invoke(null, $state, ['right']);
        $this->assertSame(['buffer' => 'abcd', 'cursor' => 4], $state);
    }
}
