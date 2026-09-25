<?php

declare(strict_types=1);

namespace Tests\Unit\Traits;

use App\Traits\HandlesLibraryJson;
use Tests\TestCase;

class HandlesLibraryJsonOwnershipTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function setFileOwnershipSetsGroupToAudioAndMakesItGroupWritable(): void
    {
        $audioGid = $this->audioGroupIdOrSkip();
        config(['filesystems.book_file_group' => 'audio']);

        $path = sys_get_temp_dir() . '/ownership_test_' . uniqid() . '.txt';
        touch($path);
        chmod($path, 0600);

        try {
            $service = new HandlesLibraryJsonOwnershipTestDouble();
            $service->exposeSetFileOwnership($path);
            clearstatcache(true, $path);

            $this->assertSame($audioGid, filegroup($path), 'File group was not set to "audio"');
            $this->assertSame('0664', substr(sprintf('%o', fileperms($path)), -4), 'File is not group-writable (0664)');
        } finally {
            @unlink($path);
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function setDirectoryOwnershipSetsGroupToAudioAndMakesItGroupWritable(): void
    {
        $audioGid = $this->audioGroupIdOrSkip();
        config(['filesystems.book_file_group' => 'audio']);

        $dir = sys_get_temp_dir() . '/ownership_test_dir_' . uniqid();
        mkdir($dir, 0700);

        try {
            $service = new HandlesLibraryJsonOwnershipTestDouble();
            $service->exposeSetDirectoryOwnership($dir);
            clearstatcache(true, $dir);

            $this->assertSame($audioGid, filegroup($dir), 'Directory group was not set to "audio"');
            $this->assertSame('0775', substr(sprintf('%o', fileperms($dir)), -4), 'Directory is not group-writable (0775)');
        } finally {
            @rmdir($dir);
        }
    }

    private function audioGroupIdOrSkip(): int
    {
        if (!function_exists('posix_getgrnam') || !function_exists('posix_getgroups')) {
            $this->markTestSkipped('POSIX group functions are unavailable.');
        }

        $audioGroup = posix_getgrnam('audio');
        if ($audioGroup === false || !in_array($audioGroup['gid'], posix_getgroups(), true)) {
            $this->markTestSkipped('The test process cannot change files to the "audio" group.');
        }

        return $audioGroup['gid'];
    }
}

class HandlesLibraryJsonOwnershipTestDouble
{
    use HandlesLibraryJson;

    public function exposeSetFileOwnership(string $path): void
    {
        $this->setFileOwnership($path);
    }

    public function exposeSetDirectoryOwnership(string $path): void
    {
        $this->setDirectoryOwnership($path);
    }
}
