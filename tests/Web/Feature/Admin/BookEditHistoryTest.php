<?php

declare(strict_types=1);

namespace Tests\Web\Feature\Admin;

use App\Models\Book;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class BookEditHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function testEditPageShowsHistoryEndingWithFirstImportAndOtherData(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'name' => 'Ada Admin']);
        $isbn = '9781234567897';
        $book = Book::factory()->create(['title' => 'Before Title', 'isbn' => $isbn]);
        Auth::login($admin);
        $book->update(['title' => 'After Title']);
        Auth::logout();

        $response = $this->actingAs($admin)->get(route('admin.books.edit', $book->id));

        $response->assertOk()->assertSeeInOrder([
            'Edit history',
            'Edited',
            'by Ada Admin',
            'Before Title',
            'After Title',
            'First import',
            'Import / command line',
            'Other data',
            'Isbn',
            $isbn,
        ]);
    }
}
