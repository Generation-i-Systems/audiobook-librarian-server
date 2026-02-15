<?php

declare(strict_types=1);

namespace Tests\Feature\Scripts;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Tests for scripts/rename_to_roman.php
 */
#[CoversNothing]
class RenameToRomanScriptTest extends TestCase
{
    private string $testDir;

    protected function setUp(): void
    {
        $this->testDir = sys_get_temp_dir() . '/roman_rename_test_' . uniqid();
        mkdir($this->testDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDir($this->testDir);
    }

    private function deleteDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }
        rmdir($dir);
    }

    public function test_renames_roman_numeral_to_arabic_at_start(): void
    {
        $src = $this->testDir . '/01 - Canto X.mp3';
        $expected = $this->testDir . '/10 - Canto X.mp3';
        touch($src);
        $output = shell_exec(sprintf(
            'php %s/scripts/rename_to_roman.php %s --verbose',
            escapeshellarg(dirname(__DIR__, 3)),
            escapeshellarg($src)
        ));
        $this->assertFileDoesNotExist($src);
        $this->assertFileExists($expected);
        $this->assertStringContainsString('Renamed', $output);
    }

    public function test_no_change_if_no_roman(): void
    {
        $src = $this->testDir . '/01 - Canto.mp3';
        touch($src);
        $output = shell_exec(sprintf(
            'php %s/scripts/rename_to_roman.php %s --verbose',
            escapeshellarg(dirname(__DIR__, 3)),
            escapeshellarg($src)
        ));
        $this->assertFileExists($src);
        $this->assertStringContainsString('No change', $output);
    }

    public function test_digits_option_default(): void
    {
        $src = $this->testDir . '/01 - Canto X.mp3';
        $expected = $this->testDir . '/010 - Canto X.mp3';
        touch($src);
        $output = shell_exec(sprintf(
            'php %s/scripts/rename_to_roman.php %s --digits=3 --verbose',
            escapeshellarg(dirname(__DIR__, 3)),
            escapeshellarg($src)
        ));
        $this->assertFileDoesNotExist($src);
        $this->assertFileExists($expected);
        $this->assertStringContainsString('Renamed', $output);
    }

    public function test_digits_option_pattern(): void
    {
        $src = $this->testDir . '/Book-X.txt';
        $expected = $this->testDir . '/Book-0010.txt';
        touch($src);
        $output = shell_exec(sprintf(
            'php %s/scripts/rename_to_roman.php %s --pattern=%s --digits=4 --verbose',
            escapeshellarg(dirname(__DIR__, 3)),
            escapeshellarg($src),
            escapeshellarg("'/Book-([IVXLCDM]+)\\.txt$/i|Book-{arabic}.txt'")
        ));
        $this->assertFileDoesNotExist($src);
        $this->assertFileExists($expected);
        $this->assertStringContainsString('Renamed', $output);
    }

    public function test_pattern_option(): void
    {
        $src = $this->testDir . '/Book-X.txt';
        $expected = $this->testDir . '/Book-10.txt';
        touch($src);
        $output = shell_exec(sprintf(
            'php %s/scripts/rename_to_roman.php %s --pattern=%s --verbose',
            escapeshellarg(dirname(__DIR__, 3)),
            escapeshellarg($src),
            escapeshellarg("'/Book-([IVXLCDM]+)\\.txt$/i|Book-{arabic}.txt'")
        ));
        $this->assertFileDoesNotExist($src);
        $this->assertFileExists($expected);
        $this->assertStringContainsString('Renamed', $output);
    }
}
