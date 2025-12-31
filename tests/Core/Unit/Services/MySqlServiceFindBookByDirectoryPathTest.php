<?php

declare(strict_types=1);

namespace Tests\Core\Unit\Services;

use App\Models\Book;
use App\Services\MySqlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MySqlServiceFindBookByDirectoryPathTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function findBookByDirectoryPathReturnsNullForEmptyPath(): void
    {
        $service = new MySqlService();

        $this->assertNull($service->findBookByDirectoryPath(''));
        $this->assertNull($service->findBookByDirectoryPath(' / '));
    }

    #[Test]
    public function findBookByDirectoryPathReturnsNullWhenNotFound(): void
    {
        $service = new MySqlService();

        $this->assertNull($service->findBookByDirectoryPath('Fantasy/Nope/Unknown'));
    }

    #[Test]
    public function findBookByDirectoryPathReturnsBookArrayWhenFound(): void
    {
        $book = Book::factory()->create([
            'title' => 'Test Title',
            'directory_path' => 'Fantasy/Author/Title',
        ]);

        $service = new MySqlService();

        $found = $service->findBookByDirectoryPath('Fantasy/Author/Title');

        $this->assertIsArray($found);
        $this->assertSame((string) $book->id, (string) ($found['id'] ?? ''));
        $this->assertSame('Test Title', $found['title'] ?? null);
        $this->assertSame('Fantasy/Author/Title', $found['directoryPath'] ?? null);
    }
}
