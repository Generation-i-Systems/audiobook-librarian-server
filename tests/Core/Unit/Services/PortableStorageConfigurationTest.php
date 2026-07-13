<?php

namespace Tests\Core\Unit\Services;

use Tests\TestCase;

class PortableStorageConfigurationTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function storageAndImportDefaultsDoNotDependOnAMachineSpecificMediaPath(): void
    {
        $bookRoot = (string) config('app.book_root');
        $backupPath = (string) config('app.database_backup_path');

        $this->assertNotSame('', $bookRoot);
        $this->assertNotSame('', $backupPath);
        $this->assertFalse(str_starts_with($bookRoot, '/media/'));
        $this->assertFalse(str_starts_with($backupPath, '/media/'));
        $this->assertSame($bookRoot . '/sync', config('app.library_repair_sync_path'));
        $this->assertSame([], config('import.roots'));
    }
}
