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
        if (!function_exists('posix_getgrnam') || posix_getgrnam('audio') === false) {
            $this->markTestSkipped('No "audio" group on this system to verify against.');
        }

        $path = sys_get_temp_dir() . '/ownership_test_' . uniqid() . '.txt';
        touch($path);
        chmod($path, 0600);

        try {
            $service = new HandlesLibraryJsonOwnershipTestDouble();
            $service->exposeSetFileOwnership($path);
            clearstatcache(true, $path);

            $audioGid = posix_getgrnam('audio')['gid'];
            $this->assertSame($audioGid, filegroup($path), 'File group was not set to "audio"');
            $this->assertSame('0664', substr(sprintf('%o', fileperms($path)), -4), 'File is not group-writable (0664)');
        } finally {
            @unlink($path);
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function setDirectoryOwnershipSetsGroupToAudioAndMakesItGroupWritable(): void
    {
        if (!function_exists('posix_getgrnam') || posix_getgrnam('audio') === false) {
            $this->markTestSkipped('No "audio" group on this system to verify against.');
        }

        $dir = sys_get_temp_dir() . '/ownership_test_dir_' . uniqid();
        mkdir($dir, 0700);

        try {
            $service = new HandlesLibraryJsonOwnershipTestDouble();
            $service->exposeSetDirectoryOwnership($dir);
            clearstatcache(true, $dir);

            $audioGid = posix_getgrnam('audio')['gid'];
            $this->assertSame($audioGid, filegroup($dir), 'Directory group was not set to "audio"');
            $this->assertSame('0775', substr(sprintf('%o', fileperms($dir)), -4), 'Directory is not group-writable (0775)');
        } finally {
            @rmdir($dir);
        }
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
