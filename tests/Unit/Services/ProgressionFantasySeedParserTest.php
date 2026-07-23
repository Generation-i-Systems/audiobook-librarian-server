<?php

namespace Tests\Unit\Services;

use App\Services\ProgressionFantasySeedParser;
use Tests\TestCase;

class ProgressionFantasySeedParserTest extends TestCase
{
    private ProgressionFantasySeedParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new ProgressionFantasySeedParser();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function parseDirectoryNameExtractsSeriesAuthorAndNarrator(): void
    {
        $result = $this->parser->parseDirectoryName('Divine Dungeon - Dakota Krout - Vikas Adam, Luke Daniels');

        $this->assertSame('Divine Dungeon', $result['series']);
        $this->assertSame(['Dakota Krout'], $result['author']);
        $this->assertSame(['Vikas Adam', 'Luke Daniels'], $result['narrator']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function parseDirectoryNameCollapsesDoubleSpacesAroundDelimiters(): void
    {
        $result = $this->parser->parseDirectoryName('Beware of Chicken - Casualfarmer  - Travis Baldree');

        $this->assertSame('Beware of Chicken', $result['series']);
        $this->assertSame(['Casualfarmer'], $result['author']);
        $this->assertSame(['Travis Baldree'], $result['narrator']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function parseDirectoryNameSupportsMultipleAuthorsAndNarrators(): void
    {
        $result = $this->parser->parseDirectoryName(
            'Casual Farming__Sowing Season - Mike Caliban, Wolfe Locke - Ashlinn Romagnoli'
        );

        $this->assertSame('Casual Farming__Sowing Season', $result['series']);
        $this->assertSame(['Mike Caliban', 'Wolfe Locke'], $result['author']);
        $this->assertSame(['Ashlinn Romagnoli'], $result['narrator']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function parseDirectoryNamePreservesSemicolonsInSeriesName(): void
    {
        $result = $this->parser->parseDirectoryName(
            'New Era Online; Life Reset - Shemer Kuznits - Jeff Hays, Laurie Catherine Winkel, Annie Ellicott'
        );

        $this->assertSame('New Era Online; Life Reset', $result['series']);
        $this->assertSame(['Shemer Kuznits'], $result['author']);
        $this->assertSame(['Jeff Hays', 'Laurie Catherine Winkel', 'Annie Ellicott'], $result['narrator']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function parseFileNameExtractsBookNumberAndTitle(): void
    {
        $result = $this->parser->parseFileName('Divine Dungeon - Book 001 - Dungeon Born.m4b', 'Divine Dungeon');

        $this->assertSame('1', $result['number']);
        $this->assertSame('Dungeon Born', $result['title']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function parseFileNameFallsBackToSeriesAndNumberWhenTitleIsMissing(): void
    {
        $result = $this->parser->parseFileName('Casual Farming - Book 001.m4b', 'Casual Farming');

        $this->assertSame('1', $result['number']);
        $this->assertSame('Casual Farming 1', $result['title']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function parseFileNameHandlesDecimalNumbers(): void
    {
        $result = $this->parser->parseFileName('Dragon Heart - Book 000.5 - Ash.m4b', 'Dragon Heart');

        $this->assertSame('0.5', $result['number']);
        $this->assertSame('Ash', $result['title']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function parseFileNameKeepsCombinedPlusNumbersAsSingleBook(): void
    {
        $result = $this->parser->parseFileName(
            'Mage Errant - Book 001+002 - Into the Labyrinth + Jewel of the Endless Erg.m4b',
            'Mage Errant'
        );

        $this->assertSame('1+2', $result['number']);
        $this->assertSame('Into the Labyrinth + Jewel of the Endless Erg', $result['title']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function parseFileNameKeepsCombinedCommaNumbersAsSingleBook(): void
    {
        $result = $this->parser->parseFileName(
            "The Beginning After The End - Book 003, 004 - Beckoning Fates, Horizon's Edge.m4b",
            'The Beginning After The End'
        );

        $this->assertSame('3, 4', $result['number']);
        $this->assertSame("Beckoning Fates, Horizon's Edge", $result['title']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function parseFileNameHandlesRangeWithoutTitle(): void
    {
        $result = $this->parser->parseFileName('Legend of the Arch Magus - Books 013-014.m4b', 'Legend of the Arch Magus');

        $this->assertSame('13-14', $result['number']);
        $this->assertSame('Legend of the Arch Magus 13-14', $result['title']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function parseFileNameFormatsSideStoryWithSeriesPrefixUsingDoubleZeroPrefix(): void
    {
        $result = $this->parser->parseFileName(
            'Mage Errant - Side Story 001 - The Gorgon Incident and Other Stories.m4b',
            'Mage Errant'
        );

        $this->assertSame('00.1', $result['number']);
        $this->assertSame('The Gorgon Incident and Other Stories', $result['title']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function parseFileNameStripsNumericOrderingPrefixAndHandlesSideStoryWithoutSeriesName(): void
    {
        $result = $this->parser->parseFileName(
            '018 - Side Story 010 - The Ugliest Maid in Castal.m4b',
            'Spellmonger'
        );

        $this->assertSame('00.10', $result['number']);
        $this->assertSame('The Ugliest Maid in Castal', $result['title']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function parseFileNameStripsRangedNumericOrderingPrefixForCombinedSideStoryFile(): void
    {
        $result = $this->parser->parseFileName(
            '035-040 - Side Story 018-023 - The Wizards of Sevendor.m4b',
            'Spellmonger'
        );

        $this->assertSame('00.18-23', $result['number']);
        $this->assertSame('The Wizards of Sevendor', $result['title']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function parseFileNameStripsNumericPrefixAndSeriesPrefixTogetherForNovelKeyword(): void
    {
        $result = $this->parser->parseFileName('048 - Novel  001 - The Talon and the Flame.m4b', 'Spellmonger');

        $this->assertSame('1', $result['number']);
        $this->assertSame('The Talon and the Flame', $result['title']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function parseFileNameStripsNumericPrefixForDecimalSpellmongerBookKeyword(): void
    {
        $result = $this->parser->parseFileName(
            '044 - Spellmonger - Book 014.5 - The Mad Mage of Sevendor.m4b',
            'Spellmonger'
        );

        $this->assertSame('14.5', $result['number']);
        $this->assertSame('The Mad Mage of Sevendor', $result['title']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function parseFileNameHandlesMissingSpaceBeforeTitleDash(): void
    {
        $result = $this->parser->parseFileName('Bog Standard Isekai - Book 001- Scarred.m4b', 'Bog Standard Isekai');

        $this->assertSame('1', $result['number']);
        $this->assertSame('Scarred', $result['title']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function parseFileNameReturnsNullForUnrecognizedStandaloneFile(): void
    {
        $result = $this->parser->parseFileName(
            'How to Defeat a Demon King in Ten Easy Steps.m4b',
            'How to Defeat a Demon King in Ten Easy Steps'
        );

        $this->assertNull($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function parseFileNameReturnsNullForCompleteSeriesOmnibusFile(): void
    {
        $result = $this->parser->parseFileName('Blessed Time - The Complete Series.m4b', 'Blessed Time');

        $this->assertNull($result);
    }
}
