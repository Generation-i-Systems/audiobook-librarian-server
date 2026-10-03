<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Imports;

use App\Services\Imports\ImportApiException;
use App\Services\Imports\ImportStagingStore;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ImportStagingStoreTest extends TestCase
{
    #[Test]
    public function unwritableStorageReturnsAStableNonRetryableError(): void
    {
        $root = sys_get_temp_dir() . '/import-staging-denied-' . bin2hex(random_bytes(6));
        mkdir($root, 0700);
        chmod($root, 0500);
        config(['import_drafts.staging_root' => $root]);

        try {
            $stream = fopen('php://temp', 'w+b');
            fwrite($stream, 'book');
            rewind($stream);
            try {
                app(ImportStagingStore::class)->append('imp_ABC123/1.part', 0, $stream, 4);
                $this->fail('An unwritable staging root must stop the upload.');
            } catch (ImportApiException $error) {
                $this->assertSame(507, $error->status);
                $this->assertSame('staging_unwritable', $error->errorCode);
                $this->assertFalse($error->retryable);
            } finally {
                fclose($stream);
            }
        } finally {
            chmod($root, 0700);
            rmdir($root);
        }
    }
}
