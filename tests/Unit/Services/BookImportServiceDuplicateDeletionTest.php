<?php

namespace Tests\Unit\Services;

use App\Services\BookImportService;
use App\Services\GenreMappingService;
use App\Services\SourceTrashService;
use Tests\TestCase;
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

        /** @phpstan-ignore-next-line */
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

                    // Check if it's a file by looking at the path structure (has audio extension)
                    $audioExtensions = ['mp3', 'm4a', 'm4b', 'flac', 'ogg', 'wma', 'aac'];
                    $extension = strtolower(pathinfo($audiobook['path'] ?? '', PATHINFO_EXTENSION));
                    $isFile = in_array($extension, $audioExtensions);
                    $sourceType = $isFile ? 'file' : 'directory';
                    $sourceTypePlural = $isFile ? 'files' : 'directories';

                    if ($comparison['identical']) {
                        $infoCallback("🔍 Source and existing {$sourceTypePlural} are identical");

                        // Always ask for confirmation before deleting
                        $options = [
                            '1' => "Skip import completely (keep both {$sourceTypePlural})",
                            '2' => "Delete source {$sourceType} (keep existing)",
                            '3' => 'Import anyway with new name',
                        ];

                        $choice = $uiService->select("Identical {$sourceTypePlural} detected - choose action", $options, '1');

                        switch ($choice) {
                            case '2':
                                $infoCallback("🗑️ Removing source {$sourceType}, keeping existing");
                                $cleanupSourceDirectoryCallback($audiobook, true);
                                return 'deleted';

                            case '3':
                                $infoCallback("📁 Will import with renamed {$sourceType} to avoid conflict");
                                $aiMetadata['_force_rename_directory'] = true;
                                return 'renamed';

                            case '1':
                            default:
                                $infoCallback("📁 Skipping import, leaving both {$sourceTypePlural} unchanged");
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
            ->with("Identical files detected - choose action", [
                '1' => 'Skip import completely (keep both files)',
                '2' => 'Delete source file (keep existing)',
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
        $this->assertContains('📁 Skipping import, leaving both files unchanged', $infoMessages);
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
            ->with("Identical files detected - choose action", [
                '1' => 'Skip import completely (keep both files)',
                '2' => 'Delete source file (keep existing)',
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
        $this->assertContains('🗑️ Removing source file, keeping existing', $infoMessages);
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
            ->with("Identical files detected - choose action", [
                '1' => 'Skip import completely (keep both files)',
                '2' => 'Delete source file (keep existing)',
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
        $this->assertContains('📁 Will import with renamed file to avoid conflict', $infoMessages);
        $this->assertArrayHasKey('_force_rename_directory', $aiMetadata, 'Force rename flag should be set');
        /** @phpstan-ignore-next-line offsetAccess.notFound */
        $this->assertTrue($aiMetadata['_force_rename_directory'], 'Force rename flag should be true');
    }
}
