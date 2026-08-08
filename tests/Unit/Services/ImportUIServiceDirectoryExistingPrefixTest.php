<?php

namespace Tests\Unit\Services;

use App\Services\ImportUIService;
use Tests\TestCase;

class ImportUIServiceDirectoryExistingPrefixTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function boldsTheFullExistingPrefixWhenItPreceedsNewSegments(): void
    {
        $uiService = new ImportUIServiceDirectoryExistingPrefixTestDouble();
        $uiService->setCurrentBook([
            'directory_existing_prefix' => 'Fantasy/J.M. Clarke & C.J. Thompson/Rune Seeker/',
        ]);

        $result = $uiService->exposeBoldExistingDirectoryPrefix(
            'Fantasy/J.M. Clarke & C.J. Thompson/Rune Seeker/04 Rune Seeker 4'
        );

        $this->assertSame(
            "\e[1mFantasy/J.M. Clarke & C.J. Thompson/Rune Seeker/\e[0m04 Rune Seeker 4",
            $result
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function returnsValueUnchangedWhenNoExistingPrefixIsRecorded(): void
    {
        $uiService = new ImportUIServiceDirectoryExistingPrefixTestDouble();
        $uiService->setCurrentBook([
            'directory_existing_prefix' => '',
        ]);

        $result = $uiService->exposeBoldExistingDirectoryPrefix('Fantasy/New Author/New Series/01 Title');

        $this->assertSame('Fantasy/New Author/New Series/01 Title', $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function boldsOnlyTheVisiblePortionWhenTheDisplayValueWasTruncated(): void
    {
        $uiService = new ImportUIServiceDirectoryExistingPrefixTestDouble();
        $uiService->setCurrentBook([
            'directory_existing_prefix' => 'Fantasy/J.M. Clarke & C.J. Thompson/Rune Seeker/',
        ]);

        // Simulate drawBookDetails() having already truncated the value before this runs.
        $result = $uiService->exposeBoldExistingDirectoryPrefix('Fantasy/J.M. Clarke...');

        $this->assertSame("\e[1mFantasy/J.M. Clarke\e[0m...", $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function doesNotBoldWhenTheRecordedPrefixDoesNotActuallyMatch(): void
    {
        $uiService = new ImportUIServiceDirectoryExistingPrefixTestDouble();
        $uiService->setCurrentBook([
            'directory_existing_prefix' => 'Fantasy/Some Other Author/',
        ]);

        $result = $uiService->exposeBoldExistingDirectoryPrefix('Fantasy/New Author/New Series/01 Title');

        $this->assertSame('Fantasy/New Author/New Series/01 Title', $result);
    }
}

class ImportUIServiceDirectoryExistingPrefixTestDouble extends ImportUIService
{
    protected function renderFull(): void
    {
    }

    public function render(): void
    {
    }

    public function table(array $headers, array $rows): void
    {
    }

    public function exposeBoldExistingDirectoryPrefix(string $displayValue): string
    {
        return $this->boldExistingDirectoryPrefix($displayValue);
    }
}
