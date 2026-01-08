<?php

namespace Tests\Unit\Services;

use App\Services\BookImportService;
use App\Services\GenreMappingService;
use App\Services\SourceTrashService;
use PHPUnit\Framework\TestCase;
use Mockery;
use Illuminate\Support\Facades\File;

class BookImportServiceDuplicateDeletionTest extends TestCase
{
    private $importService;
    private $sourceTrashService;
    private $uiService;

    protected function setUp(): void
    {
        parent::setUp();

        $genreMappingService = Mockery::mock(GenreMappingService::class);
        $this->sourceTrashService = Mockery::mock(SourceTrashService::class);
        $this->uiService = Mockery::mock(\App\Services\ImportUIService::class);

        // Mock the File facade
        File::shouldReceive('isDirectory')->andReturn(true);

        $this->importService = new class ($genreMappingService, $this->sourceTrashService) extends BookImportService {
            public function exposeProcessAudiobookDuplicateDetection(
                array $audiobook,
                array &$aiMetadata,
                $existingBook,
                callable $compareDirectoriesCallback,
                callable $cleanupSourceDirectoryCallback,
                callable $infoCallback,
                callable $warnCallback,
                callable $lineCallback,
                $uiService
            ) {
                return $this->handleDuplicateDetection(
                    $audiobook,
                    $aiMetadata,
                    $existingBook,
                    $compareDirectoriesCallback,
                    $cleanupSourceDirectoryCallback,
                    $infoCallback,
                    $warnCallback,
                    $lineCallback,
                    $uiService
                );
            }

            private function handleDuplicateDetection(
                array $audiobook,
                array &$aiMetadata,
                $existingBook,
                callable $compareDirectoriesCallback,
                callable $cleanupSourceDirectoryCallback,
                callable $infoCallback,
                callable $warnCallback,
                callable $lineCallback,
                $uiService
            ) {
                // Simulate the duplicate detection logic from processAudiobook
                $bookStoragePath = '/media/lyra_data1/audiobooks/books';
                $existingDir = $bookStoragePath . '/' . $existingBook->directory_path;

                if (File::isDirectory($existingDir)) {
                    $comparison = $compareDirectoriesCallback($audiobook['path'], $existingDir);

                    if ($comparison['identical']) {
                        $infoCallback("🔍 Source and existing directories are identical");

                        // Always ask for confirmation before deleting
                        $options = [
                            '1' => 'Skip import completely (keep both directories)',
                            '2' => 'Delete source directory (keep existing)',
                            '3' => 'Import anyway with new name',
                        ];

                        $choice = $uiService->select("Identical directories detected - choose action", $options, '1');

                        switch ($choice) {
                            case '2':
                                $infoCallback("🗑️ Removing source directory, keeping existing");
                                $cleanupSourceDirectoryCallback($audiobook, true);
                                return 'deleted';

                            case '3':
                                $infoCallback("📁 Will import with renamed directory to avoid conflict");
                                $aiMetadata['_force_rename_directory'] = true;
                                return 'renamed';

                            case '1':
                            default:
                                $infoCallback("📁 Skipping import, leaving both directories unchanged");
                                return 'skipped';
                        }
                    }
                }

                return 'no_action';
            }
        };
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testIdenticalDirectoriesRequiresUserConfirmation()
    {
        $audiobook = [
            'path' => '/media/lyra_data1/audiobooks/OpenAudible/books/7 Hours to Die.m4b',
            'name' => '7 Hours to Die',
            'files' => []
        ];

        $aiMetadata = ['title' => '7 Hours to Die'];
        $existingBook = (object) ['id' => 123, 'title' => '7 Hours to Die', 'directory_path' => 'Fiction/John Doe/7 Hours to Die'];

        $comparison = ['identical' => true];
        $compareCallback = function ($source, $target) use ($comparison) {
            return $comparison;
        };

        $cleanupCalled = false;
        $cleanupCallback = function ($audiobook, $filesAlreadyExist) use (&$cleanupCalled) {
            $cleanupCalled = true;
        };

        $infoMessages = [];
        $infoCallback = function ($message) use (&$infoMessages) {
            $infoMessages[] = $message;
        };

        // Mock UI service to return "1" (skip)
        $this->uiService->shouldReceive('select')
            ->once()
            ->with("Identical directories detected - choose action", [
                '1' => 'Skip import completely (keep both directories)',
                '2' => 'Delete source directory (keep existing)',
                '3' => 'Import anyway with new name',
            ], '1')
            ->andReturn('1');

        $result = $this->importService->exposeProcessAudiobookDuplicateDetection(
            $audiobook,
            $aiMetadata,
            $existingBook,
            $compareCallback,
            $cleanupCallback,
            $infoCallback,
            function () {
            },
            function () {
            },
            $this->uiService
        );

        // Verify that cleanup was NOT called (user chose to skip)
        $this->assertFalse($cleanupCalled, 'Cleanup should not be called when user chooses to skip');
        $this->assertEquals('skipped', $result);
        $this->assertContains('📁 Skipping import, leaving both directories unchanged', $infoMessages);
    }

    public function testIdenticalDirectoriesCanDeleteWithUserConfirmation()
    {
        $audiobook = [
            'path' => '/media/lyra_data1/audiobooks/OpenAudible/books/7 Hours to Die.m4b',
            'name' => '7 Hours to Die',
            'files' => []
        ];

        $aiMetadata = ['title' => '7 Hours to Die'];
        $existingBook = (object) ['id' => 123, 'title' => '7 Hours to Die', 'directory_path' => 'Fiction/John Doe/7 Hours to Die'];

        $comparison = ['identical' => true];
        $compareCallback = function ($source, $target) use ($comparison) {
            return $comparison;
        };

        $cleanupCalled = false;
        $cleanupCallback = function ($audiobook, $filesAlreadyExist) use (&$cleanupCalled) {
            $cleanupCalled = true;
        };

        $infoMessages = [];
        $infoCallback = function ($message) use (&$infoMessages) {
            $infoMessages[] = $message;
        };

        // Mock UI service to return "2" (delete)
        $this->uiService->shouldReceive('select')
            ->once()
            ->with("Identical directories detected - choose action", [
                '1' => 'Skip import completely (keep both directories)',
                '2' => 'Delete source directory (keep existing)',
                '3' => 'Import anyway with new name',
            ], '1')
            ->andReturn('2');

        $result = $this->importService->exposeProcessAudiobookDuplicateDetection(
            $audiobook,
            $aiMetadata,
            $existingBook,
            $compareCallback,
            $cleanupCallback,
            $infoCallback,
            function () {
            },
            function () {
            },
            $this->uiService
        );

        // Verify that cleanup WAS called (user chose to delete)
        $this->assertTrue($cleanupCalled, 'Cleanup should be called when user chooses to delete');
        $this->assertEquals('deleted', $result);
        $this->assertContains('🗑️ Removing source directory, keeping existing', $infoMessages);
    }

    public function testIdenticalDirectoriesCanImportWithNewName()
    {
        $audiobook = [
            'path' => '/media/lyra_data1/audiobooks/OpenAudible/books/7 Hours to Die.m4b',
            'name' => '7 Hours to Die',
            'files' => []
        ];

        $aiMetadata = ['title' => '7 Hours to Die'];
        $existingBook = (object) ['id' => 123, 'title' => '7 Hours to Die', 'directory_path' => 'Fiction/John Doe/7 Hours to Die'];

        $comparison = ['identical' => true];
        $compareCallback = function ($source, $target) use ($comparison) {
            return $comparison;
        };

        $cleanupCalled = false;
        $cleanupCallback = function ($audiobook, $filesAlreadyExist) use (&$cleanupCalled) {
            $cleanupCalled = true;
        };

        $infoMessages = [];
        $infoCallback = function ($message) use (&$infoMessages) {
            $infoMessages[] = $message;
        };

        // Mock UI service to return "3" (import with new name)
        $this->uiService->shouldReceive('select')
            ->once()
            ->with("Identical directories detected - choose action", [
                '1' => 'Skip import completely (keep both directories)',
                '2' => 'Delete source directory (keep existing)',
                '3' => 'Import anyway with new name',
            ], '1')
            ->andReturn('3');

        $result = $this->importService->exposeProcessAudiobookDuplicateDetection(
            $audiobook,
            $aiMetadata,
            $existingBook,
            $compareCallback,
            $cleanupCallback,
            $infoCallback,
            function () {
            },
            function () {
            },
            $this->uiService
        );

        // Verify that cleanup was NOT called (user chose to import with new name)
        $this->assertFalse($cleanupCalled, 'Cleanup should not be called when user chooses to import with new name');
        $this->assertEquals('renamed', $result);
        $this->assertContains('📁 Will import with renamed directory to avoid conflict', $infoMessages);
        $this->assertTrue(isset($aiMetadata['_force_rename_directory']), 'Force rename flag should be set');
    }
}
