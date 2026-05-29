<?php

declare(strict_types=1);

namespace Tests\Cli\Feature\Commands;

use App\Console\Commands\FixSeriesStartingWithNumberCommand;
use App\Models\Series;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FixSeriesStartingWithNumberCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Register SQLite REGEXP function for series name matching
        try {
            $pdo = DB::connection()->getPdo();
            if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $pdo->sqliteCreateFunction('REGEXP', function ($pattern, $value) {
                    return preg_match('/' . $pattern . '/', (string) $value) > 0 ? 1 : 0;
                });
            }
        } catch (\Exception $e) {
            // Non-SQLite databases support REGEXP natively
        }
    }

    #[Test]
    public function test_command_identifies_series_with_number_prefix(): void
    {
        Series::create(['name' => '1st Law Series']);
        Series::create(['name' => 'The Wheel of Time']);

        $this->artisan('series:fix-number-prefix', ['--dry-run' => true])
            ->expectsOutputToContain('Found 1 series that start with a number')
            ->assertExitCode(0);
    }

    /**
     * Test the parseDirectoryPath method in the command.
     */
    #[Test]
    public function test_parse_directory_path_method(): void
    {
        // Create a reflection of the command class to test private method
        $command = new FixSeriesStartingWithNumberCommand();
        $reflectionClass = new \ReflectionClass($command);
        $method = $reflectionClass->getMethod('parseDirectoryPath');
        $method->setAccessible(true);

        // Test with 5-part path
        $result = $method->invoke($command, 'fantasy/john_doe/Test Series/01/Book Title');
        $this->assertEquals('fantasy', $result['genre']);
        $this->assertEquals('john_doe', $result['author']);
        $this->assertEquals('Test Series', $result['series']);
        $this->assertEquals('01', $result['number']);
        $this->assertEquals('Book Title', $result['title']);

        // Test with 3-part path
        $result = $method->invoke($command, 'fantasy/john_doe/Book Title');
        $this->assertEquals('fantasy', $result['genre']);
        $this->assertEquals('john_doe', $result['author']);
        $this->assertEquals('Book Title', $result['title']);
        $this->assertArrayNotHasKey('series', $result);

        // Test with invalid path
        $result = $method->invoke($command, 'invalid_path');
        $this->assertEmpty($result);
    }

    /**
     * Test the confirmAction method in the command.
     */
    #[Test]
    public function test_confirm_action_method(): void
    {
        // Create a command instance with mocked methods
        $command = $this->getMockBuilder(FixSeriesStartingWithNumberCommand::class)
            ->onlyMethods(['choice'])
            ->getMock();

        // Set expectations for the choice method
        $command->expects($this->exactly(3))
            ->method('choice')
            ->willReturnOnConsecutiveCalls('y', 'n', '');

        // Create a reflection of the command class to test private method
        $reflectionClass = new \ReflectionClass(FixSeriesStartingWithNumberCommand::class);
        $method = $reflectionClass->getMethod('confirmAction');
        $method->setAccessible(true);

        // Test with confirmation (should return true for 'y')
        $result = $method->invokeArgs($command, ['Confirm action?']);
        $this->assertTrue($result);

        // Test with rejection (should return false for 'n')
        $result = $method->invokeArgs($command, ['Reject action?']);
        $this->assertFalse($result);

        // Test with allow skip (should return false for '')
        $result = $method->invokeArgs($command, ['Skip action?', true]);
        $this->assertFalse($result);
    }
}
