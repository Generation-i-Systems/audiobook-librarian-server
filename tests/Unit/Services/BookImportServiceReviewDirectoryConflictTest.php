<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\BookImportService;
use App\Services\GenreMappingService;
use ReflectionMethod;
use Tests\TestCase;

class BookImportServiceReviewDirectoryConflictTest extends TestCase
{
    protected BookImportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BookImportService(
            app(GenreMappingService::class),
            app(\App\Services\SourceTrashService::class)
        );
    }

    private function callResolve(
        array $metadata,
        array $audiobook,
        string $currentDirectoryPath,
        callable $selectCallback,
        callable $logMessageCallback
    ): ?string {
        $method = new ReflectionMethod(BookImportService::class, 'resolveReviewDirectoryConflict');

        return $method->invoke($this->service, $metadata, $audiobook, $currentDirectoryPath, $selectCallback, $logMessageCallback);
    }

    private function withTempRoot(callable $test): void
    {
        $bookRoot = sys_get_temp_dir() . '/review_conflict_test_' . uniqid();
        $sourceDir = sys_get_temp_dir() . '/review_conflict_source_' . uniqid();
        mkdir($bookRoot, 0775, true);
        mkdir($sourceDir, 0775, true);
        config(['app.book_root' => $bookRoot]);
        config(['filesystems.disks.books.root' => $bookRoot]);

        try {
            $test($bookRoot, $sourceDir);
        } finally {
            exec('rm -rf ' . escapeshellarg($bookRoot));
            exec('rm -rf ' . escapeshellarg($sourceDir));
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function narratorOptionAppendsNarratorSuffixToTheDirectoryPath(): void
    {
        $this->withTempRoot(function (string $bookRoot, string $sourceDir): void {
            $targetDir = $bookRoot . '/Fantasy/Some Author/Some Title';
            mkdir($targetDir, 0775, true);
            file_put_contents($targetDir . '/existing.m4b', 'x');
            file_put_contents($sourceDir . '/new.m4b', 'y');

            $result = $this->callResolve(
                ['narrator' => ['Derek Perkins']],
                ['path' => $sourceDir],
                'Fantasy/Some Author/Some Title',
                fn (string $q, array $options, string $default) => 'n',
                fn (string $msg) => null,
            );

            $this->assertSame('Fantasy/Some Author/Some Title (Derek Perkins)', $result);
        });
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function narratorOptionIsNotOfferedWhenNoNarratorIsSet(): void
    {
        $this->withTempRoot(function (string $bookRoot, string $sourceDir): void {
            $targetDir = $bookRoot . '/Fantasy/Some Author/Some Title';
            mkdir($targetDir, 0775, true);
            file_put_contents($targetDir . '/existing.m4b', 'x');
            file_put_contents($sourceDir . '/new.m4b', 'y');

            $seenOptions = null;
            $this->callResolve(
                ['narrator' => []],
                ['path' => $sourceDir],
                'Fantasy/Some Author/Some Title',
                function (string $q, array $options, string $default) use (&$seenOptions) {
                    $seenOptions = $options;
                    return '5';
                },
                fn (string $msg) => null,
            );

            $this->assertArrayNotHasKey('n', $seenOptions);
        });
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function cancelReturnsNullSoReviewContinuesUnchanged(): void
    {
        $this->withTempRoot(function (string $bookRoot, string $sourceDir): void {
            $targetDir = $bookRoot . '/Fantasy/Some Author/Some Title';
            mkdir($targetDir, 0775, true);
            file_put_contents($targetDir . '/existing.m4b', 'x');
            file_put_contents($sourceDir . '/new.m4b', 'y');

            $result = $this->callResolve(
                ['narrator' => []],
                ['path' => $sourceDir],
                'Fantasy/Some Author/Some Title',
                fn (string $q, array $options, string $default) => '5',
                fn (string $msg) => null,
            );

            $this->assertNull($result);
        });
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function renameNewReturnsASuffixedRelativePath(): void
    {
        $this->withTempRoot(function (string $bookRoot, string $sourceDir): void {
            $targetDir = $bookRoot . '/Fantasy/Some Author/Some Title';
            mkdir($targetDir, 0775, true);
            file_put_contents($targetDir . '/existing.m4b', 'x');
            file_put_contents($sourceDir . '/new.m4b', 'y');

            $result = $this->callResolve(
                ['narrator' => []],
                ['path' => $sourceDir],
                'Fantasy/Some Author/Some Title',
                fn (string $q, array $options, string $default) => '3',
                fn (string $msg) => null,
            );

            $this->assertSame('Fantasy/Some Author/Some Title_01', $result);
        });
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function mergeReturnsTheOriginalRelativePath(): void
    {
        $this->withTempRoot(function (string $bookRoot, string $sourceDir): void {
            $targetDir = $bookRoot . '/Fantasy/Some Author/Some Title';
            mkdir($targetDir, 0775, true);
            file_put_contents($targetDir . '/existing.m4b', 'x');
            file_put_contents($sourceDir . '/new.m4b', 'y');

            $result = $this->callResolve(
                ['narrator' => []],
                ['path' => $sourceDir],
                'Fantasy/Some Author/Some Title',
                fn (string $q, array $options, string $default) => '4',
                fn (string $msg) => null,
            );

            $this->assertSame('Fantasy/Some Author/Some Title', $result);
        });
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function playAndListChoicesReprompt(): void
    {
        // 'p' (play source), then 'l' (list source), then '5' (cancel) — should loop back
        // to the select prompt for each of the non-terminal choices instead of returning.
        $this->withTempRoot(function (string $bookRoot, string $sourceDir): void {
            $targetDir = $bookRoot . '/Fantasy/Some Author/Some Title';
            mkdir($targetDir, 0775, true);
            file_put_contents($targetDir . '/existing.m4b', 'x');
            file_put_contents($sourceDir . '/new.m4b', 'y');

            $choices = ['l', '5'];
            $callCount = 0;

            $result = $this->callResolve(
                ['narrator' => []],
                ['path' => $sourceDir],
                'Fantasy/Some Author/Some Title',
                function (string $q, array $options, string $default) use (&$choices, &$callCount) {
                    $callCount++;
                    return array_shift($choices);
                },
                fn (string $msg) => null,
            );

            $this->assertSame(2, $callCount);
            $this->assertNull($result);
        });
    }
}
