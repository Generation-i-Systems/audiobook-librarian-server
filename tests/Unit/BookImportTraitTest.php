<?php

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Support\Facades\Log;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Traits\BookImportTrait;
use App\Models\Genre;
use App\Models\Series;

class BookImportTraitTest extends TestCase
{
    use RefreshDatabase;

    // Helper class to access trait methods
    protected function getTraitObject()
    {
        return new class {
            use BookImportTrait {
                processDirPath as public;
            }
        };
    }

    public function setUp(): void
    {
        parent::setUp();
        Log::spy(); // Prevent actual logging
    }

    public function test_processDirPath_with_full_series_number_path()
    {
        Genre::create(['name' => 'Fiction']);
        Series::create(['name' => 'My Series']);
        $trait = $this->getTraitObject();
        $dirPath = '/Fiction/Author Name/My Series/15 Book Title';
        $book = $trait->processDirPath($dirPath);
        $this->assertEquals('Fiction', $book->genre->name);
        $this->assertEquals('Author Name', $book->author->name);
        $this->assertEquals('My Series', $book->series->name);
        $this->assertEquals('15', $book->series_number);
        $this->assertEquals('Book Title', $book->title);
    }
    public function test_processDirPath_with_full_series_fraction_path()
    {
        Genre::create(['name' => 'Fiction']);
        Series::create(['name' => 'My Series']);
        $trait = $this->getTraitObject();
        $dirPath = '/Fiction/Author Name/My Series/15.5 Book Title';
        $book = $trait->processDirPath($dirPath);
        $this->assertEquals('Fiction', $book->genre->name);
        $this->assertEquals('Author Name', $book->author->name);
        $this->assertEquals('My Series', $book->series->name);
        $this->assertEquals('15.5', $book->series_number);
        $this->assertEquals('Book Title', $book->title);
    }

    public function test_processDirPath_with_full_series_number_with_leading_zero_path()
    {
        Genre::create(['name' => 'Fiction']);
        Series::create(['name' => 'My Series']);
        $trait = $this->getTraitObject();
        $dirPath = '/Fiction/Author Name/My Series/015 Book Title';
        $book = $trait->processDirPath($dirPath);
        $this->assertEquals('Fiction', $book->genre->name);
        $this->assertEquals('Author Name', $book->author->name);
        $this->assertEquals('My Series', $book->series->name);
        $this->assertEquals('015', $book->series_number);
        $this->assertEquals('Book Title', $book->title);
    }

    public function test_processDirPath_with_full_series_number_book_number()
    {
        Genre::create(['name' => 'Fiction']);
        Series::create(['name' => 'My Series']);
        $trait = $this->getTraitObject();
        $dirPath = '/Fiction/Author Name/My Series/Book 01.5 Title';
        $book = $trait->processDirPath($dirPath);
        $this->assertEquals('Fiction', $book->genre->name);
        $this->assertEquals('Author Name', $book->author->name);
        $this->assertEquals('My Series', $book->series->name);
        $this->assertEquals('01.5', $book->series_number);
        $this->assertEquals('Book 01.5 Title', $book->title);
    }

    public function test_processDirPath_with_full_series_number_with_trailing_book_number()
    {
        Genre::create(['name' => 'Fiction']);
        Series::create(['name' => 'My Series']);
        $trait = $this->getTraitObject();
        $dirPath = '/Fiction/Author Name/My Series/Book Title 01.5';
        $book = $trait->processDirPath($dirPath);
        $this->assertEquals('Fiction', $book->genre->name);
        $this->assertEquals('Author Name', $book->author->name);
        $this->assertEquals('My Series', $book->series->name);
        $this->assertEquals('01.5', $book->series_number);
        $this->assertEquals('Book Title 01.5', $book->title);
    }

    public function test_processDirPath_with_full_series_number_in_brackets()
    {
        Genre::create(['name' => 'Fiction']);
        Series::create(['name' => 'My Series']);
        $trait = $this->getTraitObject();
        $dirPath = '/Fiction/Author Name/My Series/Book [01.5] Title';
        $book = $trait->processDirPath($dirPath);
        $this->assertEquals('Fiction', $book->genre->name);
        $this->assertEquals('Author Name', $book->author->name);
        $this->assertEquals('My Series', $book->series->name);
        $this->assertEquals('01.5', $book->series_number);
        $this->assertEquals('Book [01.5] Title', $book->title);
    }

    public function test_processDirPath_with_full_series_number_in_parenthesis()
    {
        Genre::create(['name' => 'Fiction']);
        Series::create(['name' => 'My Series']);
        $trait = $this->getTraitObject();
        $dirPath = '/Fiction/Author Name/My Series/Book (15) Title';
        $book = $trait->processDirPath($dirPath);
        $this->assertEquals('Fiction', $book->genre->name);
        $this->assertEquals('Author Name', $book->author->name);
        $this->assertEquals('My Series', $book->series->name);
        $this->assertEquals('15', $book->series_number);
        $this->assertEquals('Book (15) Title', $book->title);
    }

    public function test_processDirPath_with_full_series_number_in_braces()
    {
        Genre::create(['name' => 'Fiction']);
        Series::create(['name' => 'My Series']);
        $trait = $this->getTraitObject();
        $dirPath = '/Fiction/Author Name/My Series/Book {15} Title';
        $book = $trait->processDirPath($dirPath);
        $this->assertEquals('Fiction', $book->genre->name);
        $this->assertEquals('Author Name', $book->author->name);
        $this->assertEquals('My Series', $book->series->name);
        $this->assertEquals('15', $book->series_number);
        $this->assertEquals('Book {15} Title', $book->title);
    }

    public function test_processDirPath_with_full_series_number_in_volume()
    {
        Genre::create(['name' => 'Fiction']);
        Series::create(['name' => 'My Series']);
        $trait = $this->getTraitObject();
        $dirPath = '/Fiction/Author Name/My Series/Book Volume 01.5 Title';
        $book = $trait->processDirPath($dirPath);
        $this->assertEquals('Fiction', $book->genre->name);
        $this->assertEquals('Author Name', $book->author->name);
        $this->assertEquals('My Series', $book->series->name);
        $this->assertEquals('01.5', $book->series_number);
        $this->assertEquals('Book Volume 01.5 Title', $book->title);
    }

    public function test_processDirPath_with_full_series_number_in_volume_with_period()
    {
        Genre::create(['name' => 'Fiction']);
        Series::create(['name' => 'My Series']);
        $trait = $this->getTraitObject();
        $dirPath = '/Fiction/Author Name/My Series/Book Vol. 015 Title';
        $book = $trait->processDirPath($dirPath);
        $this->assertEquals('Fiction', $book->genre->name);
        $this->assertEquals('Author Name', $book->author->name);
        $this->assertEquals('My Series', $book->series->name);
        $this->assertEquals('015', $book->series_number);
        $this->assertEquals('Book Vol. 015 Title', $book->title);
    }

    public function test_processDirPath_with_full_series_number_path_and_subgenre_VA()
    {
        Genre::create(['name' => 'Fiction']);
        Series::create(['name' => 'My Series']);
        $trait = $this->getTraitObject();
        $dirPath = '/Fiction/VA/Author Name/My Series/15 Book Title';
        $book = $trait->processDirPath($dirPath);
        $this->assertEquals(null, $book);
    }

    public function test_processDirPath_with_full_series_and_series_grand_parent()
    {
        Genre::create(['name' => 'Fiction']);
        Series::create(['name' => 'My Series']);
        $trait = $this->getTraitObject();
        $dirPath = '/Fiction/Author Name/GrandParent Series/Parent Series/My Series/15 Book Title';
        $book = $trait->processDirPath($dirPath);
        $this->assertEquals('Fiction', $book->genre->name);
        $this->assertEquals('Author Name', $book->author->name);
        $this->assertEquals('GrandParent Series/Parent Series', $book->series->parent_name);
        $this->assertEquals('My Series', $book->series->name);
        $this->assertEquals('15', $book->series_number);
        $this->assertEquals('Book Title', $book->title);
    }

    public function test_processDirPath_with_full_series_number_path_and_subgenre()
    {
        Genre::create(['name' => 'Fiction']);
        Series::create(['name' => 'My Series']);
        $trait = $this->getTraitObject();
        $dirPath = '/Fiction/R/Author Name/My Series/15 Book Title';
        $book = $trait->processDirPath($dirPath);
        $this->assertEquals('Fiction', $book->genre->name);
        $this->assertEquals('Author Name', $book->author->name);
        $this->assertEquals('My Series', $book->series->name);
        $this->assertEquals('15', $book->series_number);
        $this->assertEquals('Book Title', $book->title);
    }

    public function test_processDirPath_with_series_no_number()
    {
        Genre::create(['name' => 'Nonfiction']);
        Series::create(['name' => 'Another Series']);
        $trait = $this->getTraitObject();
        $dirPath = '/Nonfiction/Author/Another Series/Book Title';
        $book = $trait->processDirPath($dirPath);
        $this->assertEquals('Nonfiction', $book->genre->name);
        $this->assertEquals('Author', $book->author->name);
        $this->assertEquals('Another Series', $book->series->name);
        $this->assertNull($book->series_number);
        $this->assertEquals('Book Title', $book->title);
    }

    public function test_processDirPath_with_no_series()
    {
        Genre::create(['name' => 'SciFi']);
        $trait = $this->getTraitObject();
        $dirPath = '/SciFi/Isaac Asimov/Foundation';
        $book = $trait->processDirPath($dirPath);
        $this->assertEquals('SciFi', $book->genre->name);
        $this->assertEquals('Isaac Asimov', $book->author->name);
        $this->assertNull($book->series);
        $this->assertNull($book->series_number);
        $this->assertEquals('Foundation', $book->title);
    }

    public function test_processDirPath_with_invalid_path()
    {
        $trait = $this->getTraitObject();
        $dirPath = '/invalidpath';
        $book = $trait->processDirPath($dirPath);
        $this->assertNull($book->genre);
        $this->assertNull($book->author);
        $this->assertNull($book->series);
        $this->assertNull($book->series_number);
        $this->assertNull($book->title);
        Log::shouldHaveReceived('error')->once();
    }
}
