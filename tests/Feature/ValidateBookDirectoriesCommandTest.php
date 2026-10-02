<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Book;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ValidateBookDirectoriesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function testMissingStorageRootDoesNotChangeBookAvailability(): void
    {
        $book = Book::factory()->create(['directory_exists' => true]);
        config()->set('app.book_root', sys_get_temp_dir() . '/missing-book-root-' . uniqid());

        $this->assertSame(1, Artisan::call('books:validate-directories'));
        $this->assertSame(1, Artisan::call('books:validate-directories', ['--force' => true]));
        $this->assertTrue($book->fresh()->directory_exists);
        $this->assertNull($book->fresh()->directory_last_checked);
    }
}
