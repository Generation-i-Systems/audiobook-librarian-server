<?php

namespace Tests\Unit\Console;

use App\Console\Commands\GenerateLibraryJson;
use App\Services\BookImportService;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GenerateLibraryJsonCommandTest extends TestCase
{
    private GenerateLibraryJson $command;

    /** @var \Mockery\MockInterface */
    private $importService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importService = Mockery::mock(BookImportService::class);
        $this->command = new GenerateLibraryJson($this->importService);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function validateBookDataDetectsMissingFields(): void
    {
        $book = [];

        $this->importService
            ->shouldReceive('resolveBookDirectoryPath')
            ->once()
            ->with($book)
            ->andReturn(null);

        $result = $this->invokeProtected('validateBookData', [$book]);

        $this->assertFalse($result['valid']);
        $this->assertContains('Directory is missing', $result['issues']);
        $this->assertContains('Missing title', $result['issues']);
        $this->assertContains('Missing authors', $result['issues']);
        $this->assertContains('Missing genres', $result['issues']);
        $this->assertContains('Missing audio file count', $result['issues']);
        $this->assertContains('Missing language', $result['issues']);
    }

    #[Test]
    public function validateBookDataMarksRecordAsValidWhenComplete(): void
    {
        $book = [
            'directoryPath' => 'some/path',
            'title' => 'Example Title',
            'authors' => [
                ['name' => 'Author One'],
            ],
            'genres' => [
                ['name' => 'Genre One'],
            ],
            'audio_file_count' => 3,
            'language' => 'en',
        ];

        $this->importService
            ->shouldReceive('resolveBookDirectoryPath')
            ->once()
            ->with($book)
            ->andReturn('/tmp/some/path');

        $result = $this->invokeProtected('validateBookData', [$book]);

        $this->assertTrue($result['valid']);
        $this->assertSame([], $result['issues']);
    }

    #[Test]
    public function validateGeneratedJsonDetectsMissingFile(): void
    {
        $book = ['directoryPath' => 'missing'];

        $this->importService
            ->shouldReceive('resolveBookDirectoryPath')
            ->once()
            ->with($book)
            ->andReturn(sys_get_temp_dir() . '/missing_' . uniqid());

        $result = $this->invokeProtected('validateGeneratedJson', [$book]);

        $this->assertFalse($result['valid']);
        $this->assertContains('librarian.json not found', $result['issues']);
    }

    #[Test]
    public function validateGeneratedJsonAcceptsValidJson(): void
    {
        $book = ['directoryPath' => 'existing'];
        $directory = sys_get_temp_dir() . '/library_json_' . uniqid();
        $jsonPath = $directory . '/librarian.json';

        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($jsonPath, json_encode([
            'title' => 'Example',
            'author' => ['Author'],
            'genre' => ['Genre'],
            'directoryPath' => 'existing',
        ]));

        $this->importService
            ->shouldReceive('resolveBookDirectoryPath')
            ->once()
            ->with($book)
            ->andReturn($directory);

        $result = $this->invokeProtected('validateGeneratedJson', [$book]);

        $this->assertTrue($result['valid']);
        $this->assertSame([], $result['issues']);

        unlink($jsonPath);
        rmdir($directory);
    }

    private function invokeProtected(string $method, array $arguments = [])
    {
        $reflection = new \ReflectionClass($this->command);
        $methodReflection = $reflection->getMethod($method);
        $methodReflection->setAccessible(true);

        return $methodReflection->invokeArgs($this->command, $arguments);
    }
}
