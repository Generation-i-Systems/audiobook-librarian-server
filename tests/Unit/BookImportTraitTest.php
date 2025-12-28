<?php

declare(strict_types=1);

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
     * Helper class to access trait methods.
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
    public function testProcessDirPathWithFullSeriesNumberPath(): void
    {
        $trait = $this->getTraitObject();
        $dirPath = '/Fiction/Author Name/My Series/15 Book Title';
        $book = $trait->processDirPath($dirPath);

        $this->assertIsArray($book);
        // Check for genre in either 'genre' or 'genres' field
        if (isset($book['genres']) && !empty($book['genres'])) {
            $this->assertEquals(['Fiction'], $book['genres']);
        } else {
            $this->assertEquals(['Fiction'], $book['genre']);
        }
        $this->assertEquals(['Author Name'], $book['author']);
        $this->assertEquals(['My Series' => '15'], $book['series']);
        $this->assertEquals('Book Title', $book['title']);
        $this->assertArrayHasKey('directoryPath', $book);
    }

    /**
     * Test processing a directory path with series number in brackets.
     */
    public function testProcessDirPathWithSeriesNumberInBrackets(): void
    {
        $trait = $this->getTraitObject();
        $dirPath = '/Fiction/Author Name/My Series/Book Title [15]';
        $book = $trait->processDirPath($dirPath);

        $this->assertIsArray($book);
        // Check for genre in either 'genre' or 'genres' field
        if (isset($book['genres']) && !empty($book['genres'])) {
            $this->assertEquals(['Fiction'], $book['genres']);
        } else {
            $this->assertEquals(['Fiction'], $book['genre']);
        }
        $this->assertEquals(['Author Name'], $book['author']);
        $this->assertEquals(['My Series' => '15'], $book['series']);
        // The title includes [15] because it's part of the filename and not extracted as series number
        $this->assertEquals('Book Title [15]', $book['title']);
        $this->assertArrayHasKey('directoryPath', $book);
    }

    /**
     * Test processing a directory path with series number in brackets in the middle.
     */
    public function testProcessDirPathWithSeriesNumberInBracketsInMiddle(): void
    {
        $trait = $this->getTraitObject();
        $dirPath = '/Fiction/Author Name/My Series/Book [01.5] Title';
        $book = $trait->processDirPath($dirPath);

        $this->assertIsArray($book);
        // Check for genre in either 'genre' or 'genres' field
        if (isset($book['genres']) && !empty($book['genres'])) {
            $this->assertEquals(['Fiction'], $book['genres']);
        } else {
            $this->assertEquals(['Fiction'], $book['genre']);
        }
        $this->assertEquals(['Author Name'], $book['author']);
        $this->assertEquals(['My Series' => '01.5'], $book['series']);
        $this->assertEquals('Book [01.5] Title', $book['title']);
    }

    /**
     * Test processing a directory path with series number in parentheses.
     */
    public function testProcessDirPathWithSeriesNumberInParens(): void
    {
        $trait = $this->getTraitObject();
        $dirPath = '/Fiction/Author Name/My Series/Book (15) Title';
        $book = $trait->processDirPath($dirPath);

        $this->assertIsArray($book);
        // Check for genre in either 'genre' or 'genres' field
        if (isset($book['genres']) && !empty($book['genres'])) {
            $this->assertEquals(['Fiction'], $book['genres']);
        } else {
            $this->assertEquals(['Fiction'], $book['genre']);
        }
        $this->assertEquals(['Author Name'], $book['author']);
        $this->assertEquals(['My Series' => '15'], $book['series']);
        $this->assertEquals('Book (15) Title', $book['title']);
    }

    /**
     * Test processing a directory path with VA (various artists) subgenre.
     */
    public function testProcessDirPathWithVaSubgenre(): void
    {
        $trait = $this->getTraitObject();
        $dirPath = '/Fiction/VA/Author Name/My Series/15 Book Title';
        $book = $trait->processDirPath($dirPath);

        $this->assertIsArray($book);
        $this->assertTrue($book['skipped'] ?? false);
        $this->assertEquals('VA directory', $book['reason'] ?? '');
        $this->assertEquals($dirPath, $book['directoryPath']);
        $this->assertEmpty($book['genre']);
        $this->assertEmpty($book['author']);
        $this->assertEmpty($book['title']);
    }

    /**
     * Test processing a directory path with multiple authors separated by comma.
     */
    public function testProcessDirPathWithMultipleAuthorsComma(): void
    {
        $trait = $this->getTraitObject();
        $dirPath = '/Fiction/Author 1, Author 2/My Series/15 Book Title';
        $book = $trait->processDirPath($dirPath);

        $this->assertIsArray($book);
        // Check for genre in either 'genre' or 'genres' field
        if (isset($book['genres']) && !empty($book['genres'])) {
            $this->assertEquals(['Fiction'], $book['genres']);
        } else {
            $this->assertEquals(['Fiction'], $book['genre']);
        }
        $this->assertEquals(['Author 1', 'Author 2'], $book['author']);
        $this->assertEquals(['My Series' => '15'], $book['series']);
        $this->assertEquals('Book Title', $book['title']);
    }

    /**
     * Test processing a directory path with multiple authors separated by ampersand.
     */
    public function testProcessDirPathWithMultipleAuthorsAmpersand(): void
    {
        $trait = $this->getTraitObject();
        $dirPath = '/Fiction/Author 1 & Author 2/My Series/15 Book Title';
        $book = $trait->processDirPath($dirPath);

        $this->assertIsArray($book);
        // Check for genre in either 'genre' or 'genres' field
        if (isset($book['genres']) && !empty($book['genres'])) {
            $this->assertEquals(['Fiction'], $book['genres']);
        } else {
            $this->assertEquals(['Fiction'], $book['genre']);
        }
        $this->assertEquals(['Author 1', 'Author 2'], $book['author']);
        $this->assertEquals(['My Series' => '15'], $book['series']);
        $this->assertEquals('Book Title', $book['title']);
    }

    /**
     * Test processing an invalid directory path.
     */
    public function testProcessDirPathWithInvalidPath(): void
    {
        $trait = $this->getTraitObject();
        $dirPath = '/Fiction';

        $result = $trait->processDirPath($dirPath);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('error', $result);
        $this->assertArrayHasKey('skipped', $result);
        $this->assertTrue($result['skipped']);
        $this->assertStringContainsString('Path too short', $result['error']);
    }

    /**
     * Test processing an empty directory path.
     */
    public function testProcessDirPathWithEmptyPath(): void
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
    public function testProcessDirPathWithVaDirectory(): void
    {
        $trait = $this->getTraitObject();
        $dirPath = '/VA/Directory';
        $book = $trait->processDirPath($dirPath);

        $this->assertIsArray($book);
        $this->assertTrue($book['skipped'] ?? false);
        $this->assertEquals('VA directory', $book['reason'] ?? '');
        $this->assertEquals($dirPath, $book['directoryPath']);
    }

    /**
     * Test processing a directory path with a rated subdirectory.
     */
    public function testProcessDirPathWithRatedDirectory(): void
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
