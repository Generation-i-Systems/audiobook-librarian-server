<?php

namespace Tests\Unit\Services;

use App\Services\ImportUIService;
use Tests\TestCase;

class ImportUIServiceSelectFilteredTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function typingTextThatMatchesASingleOptionThenSelectingItsKeyReturnsThatKey(): void
    {
        $uiService = new ImportUIServiceScriptedInputTestDouble(['sci', '2']);

        $options = ['1' => 'Action', '2' => 'Science Fiction', '3' => 'History'];

        $result = $uiService->selectFiltered('Genre', $options, '1');

        $this->assertSame('2', $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function typingAnExactOptionKeyImmediatelySelectsIt(): void
    {
        $uiService = new ImportUIServiceScriptedInputTestDouble(['3']);

        $options = ['1' => 'Action', '2' => 'Science Fiction', '3' => 'History'];

        $result = $uiService->selectFiltered('Genre', $options, '1');

        $this->assertSame('3', $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function selectFilteredReturnsQWhenUserQuits(): void
    {
        $uiService = new ImportUIServiceScriptedInputTestDouble(['q']);

        $result = $uiService->selectFiltered('Genre', ['1' => 'One', '2' => 'Two'], '1');

        $this->assertSame('q', $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function noMatchesRecoversAndAllowsAFollowUpSelection(): void
    {
        $uiService = new ImportUIServiceScriptedInputTestDouble(['zzz', '1']);

        $options = ['1' => 'Action', '2' => 'Science Fiction'];

        $result = $uiService->selectFiltered('Genre', $options, '1');

        $this->assertSame('1', $result);
    }
}

class ImportUIServiceScriptedInputTestDouble extends ImportUIService
{
    private array $responses;

    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    public function ask(string $question, string $default = '', bool $clearPrompt = true): string
    {
        return array_shift($this->responses) ?? '';
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
