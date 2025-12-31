<?php

namespace Tests\Cli\Feature\Commands;

use App\Models\Book;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class FixDuplicatedTitleDirectoriesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_merges_duplicated_title_directory_into_parent(): void
    {
        $tempRoot = sys_get_temp_dir() . '/books_fix_dup_' . uniqid();
        File::makeDirectory($tempRoot, 0775, true, true);

        config(['app.book_root' => $tempRoot]);

        $relativePath = 'Science/Wolfgang Langewiesche/Stick and Rudder - An Explanation of the Art of Flying';
        $title = 'Stick and Rudder - An Explanation of the Art of Flying';

        $duplicatedPath = $tempRoot . '/' . $relativePath . '/' . $title;
        File::makeDirectory($duplicatedPath, 0775, true, true);

        // Existing file already in expected directory
        $expectedDir = $tempRoot . '/' . $relativePath;
        File::makeDirectory($expectedDir, 0775, true, true);
        $existingFile = $expectedDir . '/existing.txt';
        File::put($existingFile, 'existing');

        // File to be moved from duplicated directory
        $audioFile = $duplicatedPath . '/book.m4b';
        File::put($audioFile, 'dummy');

        $book = Book::create([
            'title' => $title,
            'directory_path' => $relativePath,
            'language' => 'en',
        ]);

        Artisan::call('books:fix-duplicated-title-directories', ['--apply' => true]);

        $this->assertTrue(File::isDirectory($expectedDir));
        $this->assertFalse(File::isDirectory($duplicatedPath));
        $this->assertTrue(File::exists($expectedDir . '/book.m4b'));
        $this->assertTrue(File::exists($existingFile));

        File::deleteDirectory($tempRoot);
        $book->delete();
    }
}
