<?php

namespace Tests\Import\Unit\Services;

use App\Services\ImportUIService;
use Tests\TestCase;

class ImportUIServiceQuitOptionTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function selectReturnsQWhenUserQuits(): void
    {
        $uiService = new ImportUIServiceQuitTestDouble();

        $result = $uiService->select('Choose an option', ['1' => 'One', '2' => 'Two'], '1');

        $this->assertSame('q', $result);
    }
}

class ImportUIServiceQuitTestDouble extends ImportUIService
{
    public function ask(string $question, string $default = '', bool $clearPrompt = true): string
    {
        return 'q';
    }

    protected function renderFull(): void
    {
    }

    public function render(): void
    {
    }

    public function table(array $headers, array $rows): void
    {
    }
}
