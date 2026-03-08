<?php

declare(strict_types=1);

namespace Tests\Unit\Console\Commands;

use App\Console\Commands\ShowBookInfo;
use App\Contracts\DocumentStoreServiceInterface;
use App\Services\BookDeletionService;
use App\Services\BookFilesystemService;
use App\Services\BookPathService;
use App\Models\Book;
use App\Models\Publisher;
use App\Services\TerminalImageService;
use App\Services\PermissionsQueueService;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShowBookInfoCommandTest extends TestCase
{
    use RefreshDatabase;

    private ShowBookInfo $command;
    private DocumentStoreServiceInterface $documentStore;
    private BookDeletionService $bookDeletionService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->documentStore = Mockery::mock(DocumentStoreServiceInterface::class);
        $queueFile = storage_path('app/permissions-queue.txt');
        if (!is_dir(dirname($queueFile))) {
            mkdir(dirname($queueFile), 0775, true);
        }
        file_put_contents($queueFile, "# test\n");

        $permissionsQueue = new PermissionsQueueService();
        $this->bookDeletionService = new BookDeletionService($this->documentStore, $permissionsQueue);

        $terminalImageService = new class () extends TerminalImageService {
            public function supportsImages(): bool
            {
                return false;
            }
        };

        $bookDeletionService = $this->bookDeletionService;
        $bookModel = new Book();
        $publisherModel = new Publisher();
        $bookFilesystemService = new BookFilesystemService($this->documentStore, new BookPathService());

        $this->command = new class ($terminalImageService, $bookDeletionService, $bookModel, $publisherModel, $bookFilesystemService) extends ShowBookInfo {
            public function __construct(
                TerminalImageService $terminalImageService,
                BookDeletionService $bookDeletionService,
                Book $bookModel,
                Publisher $publisherModel,
                BookFilesystemService $bookFilesystemService
            ) {
                parent::__construct($terminalImageService, $bookDeletionService, $bookModel, $publisherModel, $bookFilesystemService);
            }

            public function wrapTextPublic(string $text, int $maxWidth): string
            {
                return $this->wrapText($text, $maxWidth);
            }
        };
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function itSkipsOpeningBrowserInTestEnvironment(): void
    {
        $book = Book::factory()->create();

        $this->artisan('books:info', ['directories' => [$book->id], '--edit' => true])
            ->expectsOutputToContain('(Skipped browser opening in test environment)')
            ->assertExitCode(0);
    }

    #[Test]
    public function itWrapsLongTokensWithoutSpaces(): void
    {
        $input = str_repeat('A', 120);
        $maxWidth = 20;

        /** @var ShowBookInfo $command */
        $command = $this->command;
        // @phpstan-ignore-next-line
        $result = $command->wrapTextPublic($input, $maxWidth);
        $lines = explode("\n", $result);

        foreach ($lines as $line) {
            $this->assertLessThanOrEqual($maxWidth, mb_strlen($line));
        }

        $this->assertGreaterThan(1, count($lines));
    }

    #[Test]
    public function itPreservesColorTagsWhileWrapping(): void
    {
        $input = '<fg=red>' . str_repeat('B', 50) . '</>';
        $maxWidth = 10;

        /** @var ShowBookInfo $command */
        $command = $this->command;
        // @phpstan-ignore-next-line
        $result = $command->wrapTextPublic($input, $maxWidth);
        $lines = explode("\n", $result);

        $visibleLengths = array_map(function (string $line): int {
            $visible = preg_replace('/<[^>]+>/', '', $line) ?? '';

            return mb_strlen($visible);
        }, $lines);

        foreach ($visibleLengths as $length) {
            $this->assertLessThanOrEqual($maxWidth, $length);
        }

        $this->assertStringContainsString('<fg=red>', $result);
        $this->assertStringContainsString('</>', $result);
    }
}
