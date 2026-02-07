<?php

namespace Tests\Feature;

use App\Services\ImportUIService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Mockery\MockInterface;
use Tests\TestCase;

class ImportBooksFromDownloadsTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_command_uses_ui_service(): void
    {
        // Arrange: Create a dummy directory and file to import
        $importDir = storage_path('import/test-audio');
        File::makeDirectory($importDir, 0755, true, true);
        // Create a dummy file larger than 10MB to meet the import criteria
        $filePath = $importDir . '/test.mp3';
        $file = fopen($filePath, 'w');
        fseek($file, 11 * 1024 * 1024);
        fwrite($file, '0');
        fclose($file);

        // Mock the ImportUIService (used when --ui=ncurses)
        $this->mock(ImportUIService::class, function (MockInterface $mock) {
            $mock->shouldReceive('initialize')->once();
            $mock->shouldReceive('drawInitialLayout')->once();
            $mock->shouldReceive('logMessage')->atLeast()->once();
            $mock->shouldReceive('updateProgress')->atLeast()->once();
            $mock->shouldReceive('setCurrentBook')->zeroOrMoreTimes();
            $mock->shouldReceive('getAllLogs')->zeroOrMoreTimes()->andReturn([]);
            $mock->shouldReceive('clear')->once();
        });

        // Act: Run the import command with --ui=ncurses to use ImportUIService
        $this->artisan('book:import', [
            'path' => [$importDir],
            '--no-backup' => true,
            '--ui' => 'ncurses',
        ])->assertExitCode(0);

        // Clean up
        File::deleteDirectory(storage_path('import'));
    }
}
