<?php

namespace Tests\Feature\Commands;

use App\Console\Commands\FixRemoteImageUrlsCommand;
use App\Contracts\DocumentStoreServiceInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Mockery;

class FixRemoteImageUrlsCommandTest extends TestCase
{
    /**
     * Test the command processes remote URLs correctly.
     */
    public function testCommandProcessesRemoteUrls(): void
    {
        // Mock the document store service
        $mockDocStore = Mockery::mock([DocumentStoreServiceInterface::class]);
        
        // Setup test data
        $testBooks = [
            [
                'id' => 'book1',
                'title' => 'Test Book 1',
                'cover_url' => 'https://example.com/cover1.jpg',
                'directoryPath' => 'Fiction/Author/Book1'
            ],
            [
                'id' => 'book2',
                'title' => 'Test Book 2',
                'cover_url' => 'local/path/cover.jpg', // Already local
                'directoryPath' => 'Fiction/Author/Book2'
            ],
            [
                'id' => 'book3',
                'title' => 'Test Book 3',
                'cover_url' => 'https://example.com/cover3.jpg',
                'directoryPath' => null // Missing directory path
            ],
            [
                'id' => 'book4',
                'title' => 'Test Book 4',
                'cover_url' => null, // No cover URL
                'directoryPath' => 'Fiction/Author/Book4'
            ]
        ];
        
        // Setup expectations
        $mockDocStore->shouldReceive('listBooks')
            ->once()
            ->andReturn($testBooks);
            
        // Only book1 should be updated
        $mockDocStore->shouldReceive('updateBook')
            ->once()
            ->with('book1', Mockery::on(function($arg) {
                return isset($arg['cover_url']) && is_string($arg['cover_url']);
            }))
            ->andReturn(true);
            
        // Bind the mock service to the container
        $this->app->instance(DocumentStoreServiceInterface::class, $mockDocStore);
        
        // Create a partial mock of the command to avoid actual HTTP requests
        $command = $this->getMockBuilder(FixRemoteImageUrlsCommand::class)
            ->setConstructorArgs([$mockDocStore])
            ->onlyMethods(['importCoverImageFromUrl'])
            ->getMock();
            
        // Mock the importCoverImageFromUrl method
        $command->expects($this->once())
            ->method('importCoverImageFromUrl')
            ->with('https://example.com/cover1.jpg', 'Fiction/Author/Book1')
            ->willReturn('Fiction/Author/Book1/cover.jpg');
        
        $this->artisan('books:fix-remote-images')
            ->expectsOutput('Starting to fix remote image URLs...')
            ->assertExitCode(0);
    }
    
    /**
     * Test the dry run option.
     */
    public function testDryRunOption(): void
    {
        // Mock the document store service
        $mockDocStore = Mockery::mock([DocumentStoreServiceInterface::class]);
        
        // Setup test data with one remote URL
        $testBooks = [
            [
                'id' => 'book1',
                'title' => 'Test Book 1',
                'cover_url' => 'https://example.com/cover1.jpg',
                'directoryPath' => 'Fiction/Author/Book1'
            ]
        ];
        
        // Setup expectations
        $mockDocStore->shouldReceive('listBooks')
            ->once()
            ->andReturn($testBooks);
            
        // No updates should be made in dry run mode
        $mockDocStore->shouldNotReceive('updateBook');
            
        // Bind the mock service to the container
        $this->app->instance(DocumentStoreServiceInterface::class, $mockDocStore);
            
        // Create a partial mock of the command
        $command = $this->getMockBuilder(FixRemoteImageUrlsCommand::class)
            ->setConstructorArgs([$mockDocStore])
            ->onlyMethods(['importCoverImageFromUrl'])
            ->getMock();
            
        // importCoverImageFromUrl should not be called in dry run mode
        $command->expects($this->never())
            ->method('importCoverImageFromUrl');
        
        $this->artisan('books:fix-remote-images --dry-run')
            ->expectsOutput('Starting to fix remote image URLs...')
            ->expectsOutput('DRY RUN MODE: No changes will be made')
            ->assertExitCode(0);
    }
    
    /**
     * Test the limit option.
     */
    public function testLimitOption(): void
    {
        // Mock the document store service
        $mockDocStore = Mockery::mock([DocumentStoreServiceInterface::class]);
        
        // Setup test data with multiple books
        $testBooks = [
            [
                'id' => 'book1',
                'title' => 'Test Book 1',
                'cover_url' => 'https://example.com/cover1.jpg',
                'directoryPath' => 'Fiction/Author/Book1'
            ],
            [
                'id' => 'book2',
                'title' => 'Test Book 2',
                'cover_url' => 'https://example.com/cover2.jpg',
                'directoryPath' => 'Fiction/Author/Book2'
            ],
            [
                'id' => 'book3',
                'title' => 'Test Book 3',
                'cover_url' => 'https://example.com/cover3.jpg',
                'directoryPath' => 'Fiction/Author/Book3'
            ]
        ];
        
        // Setup expectations
        $mockDocStore->shouldReceive('listBooks')
            ->once()
            ->andReturn($testBooks);
            
        // Only the first book should be processed due to limit=1
        $mockDocStore->shouldReceive('updateBook')
            ->once()
            ->with('book1', Mockery::any())
            ->andReturn(true);
            
        // Bind the mock service to the container
        $this->app->instance(DocumentStoreServiceInterface::class, $mockDocStore);
            
        // Create a partial mock of the command
        $command = $this->getMockBuilder(FixRemoteImageUrlsCommand::class)
            ->setConstructorArgs([$mockDocStore])
            ->onlyMethods(['importCoverImageFromUrl'])
            ->getMock();
            
        // Mock the importCoverImageFromUrl method - should be called only once
        $command->expects($this->once())
            ->method('importCoverImageFromUrl')
            ->with('https://example.com/cover1.jpg', 'Fiction/Author/Book1')
            ->willReturn('Fiction/Author/Book1/cover.jpg');
        
        $this->artisan('books:fix-remote-images --limit=1')
            ->expectsOutput('Starting to fix remote image URLs...')
            ->expectsOutput('Limiting to 1 books')
            ->assertExitCode(0);
    }
    
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
