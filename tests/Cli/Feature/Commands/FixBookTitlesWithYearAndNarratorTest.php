<?php

namespace Tests\Cli\Feature\Commands;

use App\Contracts\DocumentStoreServiceInterface;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FixBookTitlesWithYearAndNarratorTest extends TestCase
{
    private $documentStoreMock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->documentStoreMock = Mockery::mock(DocumentStoreServiceInterface::class);
        $this->app->instance(DocumentStoreServiceInterface::class, $this->documentStoreMock);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function it_fixes_title_with_year_prefix_and_narrator(): void
    {
        $books = [
            [
                'id' => '1',
                'title' => '2005 - The Colorado Kid (read by Jeffrey DeMunn)',
            ],
        ];

        $this->documentStoreMock
            ->shouldReceive('listBooks')
            ->with(1, 100, [], false)
            ->andReturn(['data' => $books, 'total' => 1]);

        $this->documentStoreMock
            ->shouldReceive('listBooks')
            ->with(2, 100, [], false)
            ->andReturn(['data' => [], 'total' => 0]);

        $this->documentStoreMock
            ->shouldReceive('updateBook')
            ->once()
            ->with('1', [
                'title' => 'The Colorado Kid',
                'release_date' => '2005-01-01',
                'narrators' => ['Jeffrey DeMunn'],
            ]);

        $this->artisan('books:fix-titles-year-narrator')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_handles_dry_run_mode(): void
    {
        $books = [
            [
                'id' => '1',
                'title' => '2005 - The Colorado Kid (read by Jeffrey DeMunn)',
            ],
        ];

        $this->documentStoreMock
            ->shouldReceive('listBooks')
            ->with(1, 100, [], false)
            ->andReturn(['data' => $books, 'total' => 1]);

        $this->documentStoreMock
            ->shouldReceive('listBooks')
            ->with(2, 100, [], false)
            ->andReturn(['data' => [], 'total' => 0]);

        $this->documentStoreMock
            ->shouldNotReceive('updateBook');

        $this->artisan('books:fix-titles-year-narrator --dry-run')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_extracts_multiple_narrators(): void
    {
        $books = [
            [
                'id' => '1',
                'title' => '2010 - Book Title (read by John Smith and Jane Doe)',
            ],
        ];

        $this->documentStoreMock
            ->shouldReceive('listBooks')
            ->with(1, 100, [], false)
            ->andReturn(['data' => $books, 'total' => 1]);

        $this->documentStoreMock
            ->shouldReceive('listBooks')
            ->with(2, 100, [], false)
            ->andReturn(['data' => [], 'total' => 0]);

        $this->documentStoreMock
            ->shouldReceive('updateBook')
            ->once()
            ->with('1', Mockery::on(function ($updates) {
                return $updates['title'] === 'Book Title'
                    && $updates['release_date'] === '2010-01-01'
                    && count($updates['narrators']) === 2
                    && in_array('John Smith', $updates['narrators'])
                    && in_array('Jane Doe', $updates['narrators']);
            }));

        $this->artisan('books:fix-titles-year-narrator')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_handles_narrated_by_pattern(): void
    {
        $books = [
            [
                'id' => '1',
                'title' => '2015 - Book Title (Narrated by Full Cast)',
            ],
        ];

        $this->documentStoreMock
            ->shouldReceive('listBooks')
            ->with(1, 100, [], false)
            ->andReturn(['data' => $books, 'total' => 1]);

        $this->documentStoreMock
            ->shouldReceive('listBooks')
            ->with(2, 100, [], false)
            ->andReturn(['data' => [], 'total' => 0]);

        $this->documentStoreMock
            ->shouldReceive('updateBook')
            ->once()
            ->with('1', [
                'title' => 'Book Title',
                'release_date' => '2015-01-01',
                'narrators' => ['Full Cast'],
            ]);

        $this->artisan('books:fix-titles-year-narrator')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_only_extracts_year_without_narrator(): void
    {
        $books = [
            [
                'id' => '1',
                'title' => '2020 - Book Without Narrator Info',
            ],
        ];

        $this->documentStoreMock
            ->shouldReceive('listBooks')
            ->with(1, 100, [], false)
            ->andReturn(['data' => $books, 'total' => 1]);

        $this->documentStoreMock
            ->shouldReceive('listBooks')
            ->with(2, 100, [], false)
            ->andReturn(['data' => [], 'total' => 0]);

        $this->documentStoreMock
            ->shouldReceive('updateBook')
            ->once()
            ->with('1', [
                'title' => 'Book Without Narrator Info',
                'release_date' => '2020-01-01',
            ]);

        $this->artisan('books:fix-titles-year-narrator')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_only_extracts_narrator_without_year(): void
    {
        $books = [
            [
                'id' => '1',
                'title' => 'Book Title (read by Narrator Name)',
            ],
        ];

        $this->documentStoreMock
            ->shouldReceive('listBooks')
            ->with(1, 100, [], false)
            ->andReturn(['data' => $books, 'total' => 1]);

        $this->documentStoreMock
            ->shouldReceive('listBooks')
            ->with(2, 100, [], false)
            ->andReturn(['data' => [], 'total' => 0]);

        $this->documentStoreMock
            ->shouldReceive('updateBook')
            ->once()
            ->with('1', [
                'title' => 'Book Title',
                'narrators' => ['Narrator Name'],
            ]);

        $this->artisan('books:fix-titles-year-narrator')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_skips_books_with_no_changes_needed(): void
    {
        $books = [
            [
                'id' => '1',
                'title' => 'Normal Book Title',
            ],
        ];

        $this->documentStoreMock
            ->shouldReceive('listBooks')
            ->with(1, 100, [], false)
            ->andReturn(['data' => $books, 'total' => 1]);

        $this->documentStoreMock
            ->shouldReceive('listBooks')
            ->with(2, 100, [], false)
            ->andReturn(['data' => [], 'total' => 0]);

        $this->documentStoreMock
            ->shouldNotReceive('updateBook');

        $this->artisan('books:fix-titles-year-narrator')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_respects_limit_option(): void
    {
        $books = [
            ['id' => '1', 'title' => '2005 - Book One (read by Narrator)'],
            ['id' => '2', 'title' => '2006 - Book Two (read by Narrator)'],
        ];

        $this->documentStoreMock
            ->shouldReceive('listBooks')
            ->with(1, 100, [], false)
            ->andReturn(['data' => $books, 'total' => 2]);

        $this->documentStoreMock
            ->shouldReceive('updateBook')
            ->once()
            ->with('1', Mockery::any());

        $this->artisan('books:fix-titles-year-narrator --limit=1')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_merges_with_existing_narrators(): void
    {
        $books = [
            [
                'id' => '1',
                'title' => '2010 - Book Title (read by New Narrator)',
                'narrators' => ['Existing Narrator'],
            ],
        ];

        $this->documentStoreMock
            ->shouldReceive('listBooks')
            ->with(1, 100, [], false)
            ->andReturn(['data' => $books, 'total' => 1]);

        $this->documentStoreMock
            ->shouldReceive('listBooks')
            ->with(2, 100, [], false)
            ->andReturn(['data' => [], 'total' => 0]);

        $this->documentStoreMock
            ->shouldReceive('updateBook')
            ->once()
            ->with('1', Mockery::on(function ($updates) {
                return count($updates['narrators']) === 2
                    && in_array('Existing Narrator', $updates['narrators'])
                    && in_array('New Narrator', $updates['narrators']);
            }));

        $this->artisan('books:fix-titles-year-narrator')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_skips_books_with_future_years(): void
    {
        $futureYear = ((int) date('Y')) + 1;
        $books = [
            [
                'id' => '1',
                'title' => $futureYear . ' - Future Book',
            ],
        ];

        $this->documentStoreMock
            ->shouldReceive('listBooks')
            ->with(1, 100, [], false)
            ->andReturn(['data' => $books, 'total' => 1]);

        $this->documentStoreMock
            ->shouldReceive('listBooks')
            ->with(2, 100, [], false)
            ->andReturn(['data' => [], 'total' => 0]);

        // Should NOT update anything - title stays unchanged
        $this->documentStoreMock
            ->shouldNotReceive('updateBook');

        $this->artisan('books:fix-titles-year-narrator')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_skips_books_with_years_before_1700(): void
    {
        $books = [
            [
                'id' => '1',
                'title' => '1699 - Ancient Book',
            ],
        ];

        $this->documentStoreMock
            ->shouldReceive('listBooks')
            ->with(1, 100, [], false)
            ->andReturn(['data' => $books, 'total' => 1]);

        $this->documentStoreMock
            ->shouldReceive('listBooks')
            ->with(2, 100, [], false)
            ->andReturn(['data' => [], 'total' => 0]);

        // Should NOT update anything - title stays unchanged
        $this->documentStoreMock
            ->shouldNotReceive('updateBook');

        $this->artisan('books:fix-titles-year-narrator')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_extracts_year_from_parentheses_at_end(): void
    {
        $books = [
            [
                'id' => '1',
                'title' => 'Book Title (2008)',
            ],
        ];

        $this->documentStoreMock
            ->shouldReceive('listBooks')
            ->with(1, 100, [], false)
            ->andReturn(['data' => $books, 'total' => 1]);

        $this->documentStoreMock
            ->shouldReceive('listBooks')
            ->with(2, 100, [], false)
            ->andReturn(['data' => [], 'total' => 0]);

        $this->documentStoreMock
            ->shouldReceive('updateBook')
            ->once()
            ->with('1', [
                'title' => 'Book Title',
                'release_date' => '2008-01-01',
            ]);

        $this->artisan('books:fix-titles-year-narrator')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_extracts_year_and_narrator_together(): void
    {
        $books = [
            [
                'id' => '1',
                'title' => 'Book Title (2010) (read by John Doe)',
            ],
        ];

        $this->documentStoreMock
            ->shouldReceive('listBooks')
            ->with(1, 100, [], false)
            ->andReturn(['data' => $books, 'total' => 1]);

        $this->documentStoreMock
            ->shouldReceive('listBooks')
            ->with(2, 100, [], false)
            ->andReturn(['data' => [], 'total' => 0]);

        $this->documentStoreMock
            ->shouldReceive('updateBook')
            ->once()
            ->with('1', [
                'title' => 'Book Title',
                'release_date' => '2010-01-01',
                'narrators' => ['John Doe'],
            ]);

        $this->artisan('books:fix-titles-year-narrator')
            ->assertExitCode(0);
    }
}
