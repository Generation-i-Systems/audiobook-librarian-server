<?php

namespace Tests\Unit;

use App\Traits\BookImportTrait;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Log;

class BookImportTraitTest extends TestCase
{
    /**
     * Creates the application.
     *
     * @return \Illuminate\Foundation\Application
     */
    public function createApplication()
    {
        $app = require __DIR__ . '/../../bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        return $app;
    }

    /**
     * Helper class to access trait methods
     */
    protected function getTraitObject()
    {
        return new class () {
            use BookImportTrait {
                processDirPath as public;
            }
        };
    }

    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();
        Log::spy(); // Prevent actual logging
    }

    /**
     * Test processing a directory path with a full series number.
     */
    public function test_process_dir_path_with_full_series_number_path()
    {
        $trait = $this->getTraitObject();
        $dirPath = '/Fiction/Author Name/My Series/15 Book Title';
        $book = $trait->processDirPath($dirPath);

        $this->assertIsArray($book);
        $this->assertEquals(['Fiction'], $book['genre']);
        $this->assertEquals(['Author Name'], $book['author']);
        $this->assertEquals(['My Series' => '15'], $book['series']);
        $this->assertEquals('Book Title', $book['title']);
        $this->assertArrayHasKey('directory_path', $book);
    }

    /**
     * Test processing a directory path with series number in brackets.
     */
    public function test_process_dir_path_with_series_number_in_brackets()
    {
        $trait = $this->getTraitObject();
        $dirPath = '/Fiction/Author Name/My Series/Book Title [15]';
        $book = $trait->processDirPath($dirPath);

        $this->assertIsArray($book);
        $this->assertEquals(['Fiction'], $book['genre']);
        $this->assertEquals(['Author Name'], $book['author']);
        $this->assertEquals(['My Series' => '15'], $book['series']);
        $this->assertEquals('Book Title [15]', $book['title']);
        $this->assertArrayHasKey('directory_path', $book);
    }

    /**
     * Test processing a directory path with series number in brackets in the middle.
     */
    public function test_process_dir_path_with_series_number_in_brackets_in_middle()
    {
        $trait = $this->getTraitObject();
        $dirPath = '/Fiction/Author Name/My Series/Book [01.5] Title';
        $book = $trait->processDirPath($dirPath);

        $this->assertIsArray($book);
        $this->assertEquals(['Fiction'], $book['genre']);
        $this->assertEquals(['Author Name'], $book['author']);
        $this->assertEquals(['My Series' => '01.5'], $book['series']);
        $this->assertEquals('Book [01.5] Title', $book['title']);
    }

    /**
     * Test processing a directory path with series number in parentheses.
     */
    public function test_process_dir_path_with_series_number_in_parens()
    {
        $trait = $this->getTraitObject();
        $dirPath = '/Fiction/Author Name/My Series/Book (15) Title';
        $book = $trait->processDirPath($dirPath);

        $this->assertIsArray($book);
        $this->assertEquals(['Fiction'], $book['genre']);
        $this->assertEquals(['Author Name'], $book['author']);
        $this->assertEquals(['My Series' => '15'], $book['series']);
        $this->assertEquals('Book (15) Title', $book['title']);
    }

    /**
     * Test processing a directory path with VA (various artists) subgenre.
     */
    public function test_process_dir_path_with_va_subgenre()
    {
        $trait = $this->getTraitObject();
        $dirPath = '/Fiction/VA/Author Name/My Series/15 Book Title';
        $book = $trait->processDirPath($dirPath);

        $this->assertIsArray($book);
        $this->assertTrue($book['skipped'] ?? false);
        $this->assertEquals('VA directory', $book['reason'] ?? '');
        $this->assertEquals($dirPath, $book['directory_path']);
        $this->assertEmpty($book['genre']);
        $this->assertEmpty($book['author']);
        $this->assertEmpty($book['title']);
    }

    /**
     * Test processing a directory path with multiple authors separated by comma.
     */
    public function test_process_dir_path_with_multiple_authors_comma()
    {
        $trait = $this->getTraitObject();
        $dirPath = '/Fiction/Author 1, Author 2/My Series/15 Book Title';
        $book = $trait->processDirPath($dirPath);

        $this->assertIsArray($book);
        $this->assertEquals(['Fiction'], $book['genre']);
        $this->assertEquals(['Author 1', 'Author 2'], $book['author']);
        $this->assertEquals(['My Series' => '15'], $book['series']);
        $this->assertEquals('Book Title', $book['title']);
    }

    /**
     * Test processing a directory path with multiple authors separated by ampersand.
     */
    public function test_process_dir_path_with_multiple_authors_ampersand()
    {
        $trait = $this->getTraitObject();
        $dirPath = '/Fiction/Author 1 & Author 2/My Series/15 Book Title';
        $book = $trait->processDirPath($dirPath);

        $this->assertIsArray($book);
        $this->assertEquals(['Fiction'], $book['genre']);
        $this->assertEquals(['Author 1', 'Author 2'], $book['author']);
        $this->assertEquals(['My Series' => '15'], $book['series']);
        $this->assertEquals('Book Title', $book['title']);
    }

    /**
     * Test processing an invalid directory path.
     */
    public function test_process_dir_path_with_invalid_path()
    {
        $trait = $this->getTraitObject();
        $dirPath = '/Invalid/Path';
        $book = $trait->processDirPath($dirPath);

        $this->assertIsArray($book);
        $this->assertArrayHasKey('error', $book);
        $this->assertArrayHasKey('skipped', $book);
        $this->assertTrue($book['skipped']);
        $this->assertStringContainsString('No title in path', $book['error']);
    }

    /**
     * Test processing an empty directory path.
     */
    public function test_process_dir_path_with_empty_path()
    {
        $trait = $this->getTraitObject();
        $dirPath = '';
        $book = $trait->processDirPath($dirPath);

        $this->assertIsArray($book);
        $this->assertArrayHasKey('error', $book);
        $this->assertArrayHasKey('skipped', $book);
        $this->assertTrue($book['skipped']);
        $this->assertStringContainsString('Empty directory path', $book['error']);
    }

    /**
     * Test processing a VA (various artists) directory.
     */
    public function test_process_dir_path_with_va_directory()
    {
        $trait = $this->getTraitObject();
        $dirPath = '/VA/Directory';
        $book = $trait->processDirPath($dirPath);

        $this->assertIsArray($book);
        $this->assertTrue($book['skipped'] ?? false);
        $this->assertEquals('VA directory', $book['reason'] ?? '');
        $this->assertEquals($dirPath, $book['directory_path']);
    }

    /**
     * Test processing a directory path with a rated subdirectory.
     */
    public function test_process_dir_path_with_rated_directory()
    {
        $trait = $this->getTraitObject();
        $dirPath = '/Fiction/R/Author Name/Book Title';
        $book = $trait->processDirPath($dirPath);

        $this->assertIsArray($book);
        $this->assertEquals(['Fiction'], $book['genre']);
        $this->assertEquals(['Author Name'], $book['author']);
        $this->assertEquals('Book Title', $book['title']);
        $this->assertArrayNotHasKey('skipped', $book);
    }
}
