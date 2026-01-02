<?php

namespace Tests\Import\Unit;

use App\Console\Commands\ImportBooksFromDownloads;
use Tests\TestCase;

class ImportBooksFromDownloadsFileOperationTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function getFileOperationDefaultsToMove(): void
    {
        $command = new ImportBooksFromDownloadsFileOperationTestDouble();
        $command->options = ['copy-files' => false];

        $this->assertSame('move', $command->exposeGetFileOperation());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function getFileOperationReturnsCopyWhenCopyFilesOptionSet(): void
    {
        $command = new ImportBooksFromDownloadsFileOperationTestDouble();
        $command->options = ['copy-files' => true];

        $this->assertSame('copy', $command->exposeGetFileOperation());
    }
}

class ImportBooksFromDownloadsFileOperationTestDouble extends ImportBooksFromDownloads
{
    public array $options = [];

    public function __construct()
    {
        parent::__construct(null);
    }

    public function exposeGetFileOperation(): string
    {
        return $this->getFileOperation();
    }

    public function option($key = null)
    {
        if ($key === null) {
            return $this->options;
        }

        return $this->options[$key] ?? null;
    }
}
