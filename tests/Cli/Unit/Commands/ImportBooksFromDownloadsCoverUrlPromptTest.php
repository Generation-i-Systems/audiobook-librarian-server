<?php

namespace Tests\Cli\Unit\Commands;

use App\Console\Commands\ImportBooksFromDownloads;
use Tests\TestCase;

class ImportBooksFromDownloadsCoverUrlPromptTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function promptForCoverUrlPrefillsAndKeepsCurrentWhenBlank(): void
    {
        $command = new ImportBooksFromDownloadsCoverUrlPromptTestDouble();

        $command->askInlineResponses = [''];
        $result = $command->exposePromptForCoverUrl('https://example.com/old.jpg');

        $this->assertSame('https://example.com/old.jpg', $result);
        $this->assertSame(['Cover URL', 'https://example.com/old.jpg'], $command->lastAskInlineArgs);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function promptForCoverUrlReturnsNewUrlWhenProvided(): void
    {
        $command = new ImportBooksFromDownloadsCoverUrlPromptTestDouble();

        $command->askInlineResponses = ['https://example.com/new.jpg'];
        $result = $command->exposePromptForCoverUrl('https://example.com/old.jpg');

        $this->assertSame('https://example.com/new.jpg', $result);
        $this->assertSame(['Cover URL', 'https://example.com/old.jpg'], $command->lastAskInlineArgs);
    }
}

class ImportBooksFromDownloadsCoverUrlPromptTestDouble extends ImportBooksFromDownloads
{
    public array $askInlineResponses = [];

    /** @var array{0:string,1:string}|null */
    public ?array $lastAskInlineArgs = null;

    public function __construct()
    {
        parent::__construct(null);
    }

    public function exposePromptForCoverUrl(string $currentCoverUrl): string
    {
        return $this->promptForCoverUrl($currentCoverUrl);
    }

    protected function askInline(string $question, ?string $default = ''): string
    {
        $this->lastAskInlineArgs = [$question, $default];

        if (count($this->askInlineResponses) === 0) {
            return '';
        }

        return array_shift($this->askInlineResponses);
    }
}
