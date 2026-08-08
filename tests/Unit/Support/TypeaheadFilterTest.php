<?php

namespace Tests\Unit\Support;

use App\Support\TypeaheadFilter;
use Tests\TestCase;

class TypeaheadFilterTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function emptyQueryReturnsAllOptionsUnchanged(): void
    {
        $options = ['1' => 'Action', '2' => 'Classic'];

        $this->assertSame($options, TypeaheadFilter::filter($options, ''));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function filtersOutOptionsThatDoNotContainTheQuery(): void
    {
        $options = ['1' => 'Action', '2' => 'Classic', '3' => 'Computer'];

        $result = TypeaheadFilter::filter($options, 'act');

        $this->assertSame(['1' => 'Action'], $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function matchIsCaseInsensitive(): void
    {
        $options = ['1' => 'Action'];

        $this->assertSame(['1' => 'Action'], TypeaheadFilter::filter($options, 'ACT'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function ordersMatchesByEarliestOccurrenceOfTheQueryFirst(): void
    {
        // "Science" appears at position 8 in "Non Fiction Science" (contrived) vs
        // position 0 in "Science Fiction" - earliest match should sort first.
        $options = [
            '1' => 'General Science',
            '2' => 'Science',
            '3' => 'Computer Science',
        ];

        $result = TypeaheadFilter::filter($options, 'science');

        $this->assertSame([2, 1, 3], array_keys($result));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function tiesInMatchPositionAreBrokenAlphabetically(): void
    {
        $options = [
            '1' => 'Classic',
            '2' => 'Church',
        ];

        // Both labels have the query at position 0 ("Church" and "Classic" both start with "c").
        $result = TypeaheadFilter::filter($options, 'c');

        $this->assertSame([2, 1], array_keys($result));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function returnsEmptyArrayWhenNothingMatches(): void
    {
        $options = ['1' => 'Action'];

        $this->assertSame([], TypeaheadFilter::filter($options, 'zzz'));
    }
}
