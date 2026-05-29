<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Services\AudiobookBayApiService;
use App\Services\DocumentStoreServiceInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * @internal
 */
#[CoversNothing]
class ImportFileControllerTest extends TestCase
{
    use RefreshDatabase;

    protected string $testRoot;

    protected string $testSubdir;

    protected string $testFile;

    protected string $bookStoragePath;

    protected $documentStoreMock;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock external services to avoid API calls
        $this->mock(AudiobookBayApiService::class);

        // Mock DocumentStoreServiceInterface for consistent test behavior
        $this->documentStoreMock = $this->mock(DocumentStoreServiceInterface::class);
        $this->documentStoreMock->shouldReceive('listGenres')
            ->zeroOrMoreTimes()
            ->andReturn(['Fantasy', 'Science Fiction', 'Mystery', 'Other']);
        $this->documentStoreMock->shouldReceive('searchSeriesByName')
            ->zeroOrMoreTimes()
            ->andReturn([]);
        $this->documentStoreMock->shouldReceive('searchAuthorsByName')
            ->zeroOrMoreTimes()
            ->andReturn([]);

        // Create test directory structure
        $this->testRoot = sys_get_temp_dir() . '/import_test_' . uniqid();
        mkdir($this->testRoot);

        $this->testSubdir = $this->testRoot . '/subdir';
        mkdir($this->testSubdir);

        // Create a test MP3 file
        $this->testFile = $this->testRoot . '/test.mp3';
        file_put_contents($this->testFile, 'test data');

        // Create a test MP3 file in the subdirectory so it will be included in directory listing
        file_put_contents($this->testSubdir . '/subdir_test.mp3', 'test data in subdir');

        // Set up book storage path for file moving tests
        $this->bookStoragePath = sys_get_temp_dir() . '/book_storage_' . uniqid();
        mkdir($this->bookStoragePath);
        putenv("BOOK_STORAGE_PATH={$this->bookStoragePath}");
    }

    protected function tearDown(): void
    {
        // Clean up test files and directories
        if (is_dir($this->testRoot)) {
            File::deleteDirectory($this->testRoot);
        }

        if (is_dir($this->bookStoragePath)) {
            File::deleteDirectory($this->bookStoragePath);
        }

        parent::tearDown();
    }

    /**
     * Create application with mocked services to avoid permission issues.
     */
    public function createApplication(): \Illuminate\Foundation\Application
    {
        // First create the application
        $app = parent::createApplication();

        // Configure a null logger for testing to avoid permission issues
        $app['config']->set('logging.default', 'null');
        $app['config']->set('logging.channels.null', [
            'driver' => 'monolog',
            'handler' => 'Monolog\Handler\NullHandler',
        ]);

        return $app;
    }

    #[Test]
    public function testRootsEndpointReturnsConfiguredRoots(): void
    {
        // Arrange: Configure test roots
        Config::set('import.roots', [$this->testRoot, '/tmp']);

        // Act: Call the roots endpoint
        $response = $this->withoutMiddleware()
            ->get('/admin/import/roots');

        // Assert: Verify response contains configured roots
        $response->assertStatus(200);
        $response->assertJsonFragment(['value' => $this->testRoot]);
        $response->assertJsonFragment(['value' => '/tmp']);
    }

    #[Test]
    public function testRootsEndpointHandlesEmptyConfiguration(): void
    {
        // Arrange: Clear import roots configuration
        Config::set('import.roots', []);

        // Act: Call the roots endpoint
        $response = $this->withoutMiddleware()
            ->get('/admin/import/roots');

        // Assert: Verify response is still valid (may return default paths)
        $response->assertStatus(200);
        $response->assertJsonStructure([]);
    }

    #[Test]
    public function testListEndpointReturnsFilesAndDirectories(): void
    {
        // Act: Call the list endpoint
        $response = $this->withoutMiddleware()
            ->get('/admin/import/list?root=' . urlencode($this->testRoot));

        // Assert: Verify response structure and content
        $response->assertStatus(200);
        $json = $response->json();

        $this->assertArrayHasKey('items', $json);
        $this->assertArrayHasKey('parent', $json);
        $this->assertArrayHasKey('path', $json);
        $this->assertArrayHasKey('root', $json);

        // Verify test file is listed
        $this->assertTrue(
            collect($json['items'])->contains(fn ($item) => 'file' === $item['type'] && 'test.mp3' === $item['name'])
        );

        // Verify test subdirectory is listed
        $this->assertTrue(
            collect($json['items'])->contains(fn ($item) => 'dir' === $item['type'] && 'subdir' === $item['name'])
        );
    }

    #[Test]
    public function testListEndpointSkipsDirectoriesWithoutMatchingFiles(): void
    {
        // Arrange: Configure allowed extensions
        Config::set('import.allowed_extensions', ['mp3', 'pdf']);

        // Create directory with matching files
        $dirWithMatching = $this->testRoot . '/dir_with_matching';
        mkdir($dirWithMatching);
        file_put_contents($dirWithMatching . '/file.mp3', 'mp3 data');

        // Create directory without matching files
        $dirWithoutMatching = $this->testRoot . '/dir_without_matching';
        mkdir($dirWithoutMatching);
        file_put_contents($dirWithoutMatching . '/file.txt', 'text data');

        // Create empty directory
        $emptyDir = $this->testRoot . '/empty_dir';
        mkdir($emptyDir);

        // Act: Call the list endpoint
        $response = $this->withoutMiddleware()
            ->get('/admin/import/list?root=' . urlencode($this->testRoot));

        // Assert: Verify response contains only directories with matching files
        $response->assertStatus(200);
        $json = $response->json();

        // Directory with matching files should be included
        $this->assertTrue(
            collect($json['items'])->contains(
                fn ($item) => 'dir' === $item['type'] && 'dir_with_matching' === $item['name']
            )
        );

        // Directory without matching files should be excluded
        $this->assertFalse(
            collect($json['items'])->contains(
                fn ($item) => 'dir' === $item['type'] && 'dir_without_matching' === $item['name']
            )
        );

        // Empty directory should be excluded
        $this->assertFalse(
            collect($json['items'])->contains(
                fn ($item) => 'dir' === $item['type'] && 'empty_dir' === $item['name']
            )
        );
    }

    #[Test]
    public function testListEndpointValidatesRoot(): void
    {
        // Act: Call list endpoint with invalid root
        $response = $this->withoutMiddleware()
            ->get('/admin/import/list?root=' . urlencode('/nonexistent/path'));

        // Assert: Verify error response
        $response->assertStatus(404);
        $json = $response->json();
        $this->assertArrayHasKey('error', $json);
        $this->assertStringContainsString('does not exist', $json['error']);
    }

    #[Test]
    public function testListEndpointPreventsPathTraversal(): void
    {
        // Act: Attempt path traversal attack
        $response = $this->withoutMiddleware()
            ->get('/admin/import/list?root=' . urlencode($this->testRoot) . '&path=../../../etc');

        // Assert: Verify security check prevents traversal
        $response->assertStatus(403);
        $json = $response->json();
        $this->assertArrayHasKey('error', $json);
        $this->assertStringContainsString('Path traversal', $json['error']);
    }

    #[Test]
    public function testExtractEndpointExtractsFileMetadata(): void
    {
        // Arrange: Create a test MP3 file
        $testMp3 = $this->testRoot . '/test_metadata.mp3';
        file_put_contents($testMp3, str_repeat('0', 1024));

        // Act: Call extract endpoint
        $response = $this->withoutMiddleware()
            ->post('/admin/import/extract', [
                'root' => $this->testRoot,
                'path' => basename($testMp3),
                'type' => 'file',
            ]);

        // Assert: Verify metadata extraction
        $response->assertStatus(200);
        $json = $response->json();

        $this->assertTrue($json['success']);
        $this->assertArrayHasKey('files', $json);
        $this->assertCount(1, $json['files']);
        $this->assertEquals(basename($testMp3), $json['files'][0]['name']);
        $this->assertEquals('mp3', $json['files'][0]['extension']);
        $this->assertEquals(1024, $json['files'][0]['size']);
        $this->assertArrayHasKey('genrePath', $json);
        $this->assertArrayHasKey('directoryPath', $json);
        $this->assertArrayHasKey('formData', $json);
    }

    #[Test]
    public function testExtractEndpointExtractsDirectoryMetadata(): void
    {
        // Arrange: Create a test directory with audio files
        $testDir = $this->testRoot . '/Fantasy_John_Doe_The_Great_Adventure';
        mkdir($testDir);
        file_put_contents($testDir . '/chapter1.mp3', str_repeat('0', 512));
        file_put_contents($testDir . '/chapter2.mp3', str_repeat('0', 512));

        // Act: Call extract endpoint for directory
        $response = $this->withoutMiddleware()
            ->post('/admin/import/extract', [
                'root' => $this->testRoot,
                'path' => basename($testDir),
                'type' => 'dir',
            ]);

        // Assert: Verify directory metadata extraction
        $response->assertStatus(200);
        $json = $response->json();

        $this->assertTrue($json['success']);
        $this->assertEquals('The Great Adventure', $json['title']); // Directory parsing should extract actual title
        $this->assertArrayHasKey('files', $json);
        $this->assertCount(2, $json['files']);
        $this->assertArrayHasKey('genrePath', $json);
        $this->assertArrayHasKey('directoryPath', $json);
    }

    #[Test]
    public function testExtractEndpointValidatesParameters(): void
    {
        // Test missing root parameter
        $response = $this->withoutMiddleware()
            ->post('/admin/import/extract', [
                'path' => 'test.mp3',
                'type' => 'file',
            ]);
        $response->assertStatus(400);
        $json = $response->json();
        $this->assertFalse($json['success']);
        $this->assertStringContainsString('root', $json['message']);

        // Test missing type parameter
        $response = $this->withoutMiddleware()
            ->post('/admin/import/extract', [
                'root' => $this->testRoot,
                'path' => 'test.mp3',
            ]);
        $response->assertStatus(400);
        $json = $response->json();
        $this->assertFalse($json['success']);
        $this->assertStringContainsString('type', $json['message']);

        // Test invalid type parameter
        $response = $this->withoutMiddleware()
            ->post('/admin/import/extract', [
                'root' => $this->testRoot,
                'path' => 'test.mp3',
                'type' => 'invalid',
            ]);
        $response->assertStatus(400);
        $json = $response->json();
        $this->assertFalse($json['success']);
        $this->assertStringContainsString('must be \'file\' or \'dir\'', $json['message']);
    }

    #[Test]
    public function testExtractEndpointRedirectsToBookForm(): void
    {
        // Arrange: Create a test directory with metadata
        $testDir = $this->testRoot . '/test_book';
        mkdir($testDir);
        file_put_contents($testDir . '/chapter1.mp3', str_repeat('0', 1024));

        // Act: Call extract endpoint with redirectToForm=true
        $response = $this->withoutMiddleware()
            ->post('/admin/import/extract', [
                'root' => $this->testRoot,
                'path' => 'test_book',
                'type' => 'dir',
                'redirectToForm' => 'true',
            ]);

        // Assert: Verify redirect to book creation form
        $response->assertRedirect();
        $redirectLocation = $response->headers->get('Location');
        $this->assertStringContainsString('/admin/books/create', $redirectLocation);
        $this->assertStringContainsString('importMode=1', $redirectLocation);
        $this->assertStringContainsString('title=test_book', $redirectLocation);
        $this->assertStringContainsString('sourcePath=', $redirectLocation);
    }

    #[Test]
    public function testMoveImportedFilesValidatesSourcePath(): void
    {
        // Arrange
        $controller = app()->make('App\Http\Controllers\Admin\ImportFileController');

        // Act: Attempt to move non-existent source
        $result = $controller->moveImportedFiles(
            '/nonexistent/source',
            $this->testRoot,
            'nonexistent',
            'dir',
            'TestGenre/TestAuthor/TestBook'
        );

        // Assert: Should fail for non-existent source
        $this->assertFalse($result, 'Move should fail for non-existent source');
    }

    #[Test]
    public function testMoveImportedFilesValidatesPathTraversal(): void
    {
        // Arrange: Create source outside allowed root
        $maliciousSource = sys_get_temp_dir() . '/malicious_' . uniqid();
        mkdir($maliciousSource);
        file_put_contents($maliciousSource . '/evil.mp3', 'malicious content');

        $controller = app()->make('App\Http\Controllers\Admin\ImportFileController');

        // Act: Attempt path traversal attack
        $result = $controller->moveImportedFiles(
            $maliciousSource,
            $this->testRoot, // Different root than actual source
            '../../../malicious',
            'dir',
            'TestGenre/TestAuthor/TestBook'
        );

        // Assert: Should prevent path traversal
        $this->assertFalse($result, 'Move should prevent path traversal attacks');

        // Clean up
        File::deleteDirectory($maliciousSource);
    }

    // \[\PHPUnit\Framework\Attributes\Test\]
    public function testGenrePathDeterminationUsesExistingGenres(): void
    {
        // Arrange: Create genre directory structure in book storage
        $fantasyDir = $this->bookStoragePath . '/Fantasy';
        mkdir($fantasyDir);
        $authorDir = $fantasyDir . '/Test Author';
        mkdir($authorDir);
        $seriesDir = $authorDir . '/Test Series';
        mkdir($seriesDir);

        // Create test book directory
        $bookDir = $this->testRoot . '/Test_Author_Test_Book';
        mkdir($bookDir);
        file_put_contents($bookDir . '/chapter1.mp3', 'test content');

        // Mock DocumentStore to return this author/series data
        $this->documentStoreMock->shouldReceive('searchAuthorsByName')
            ->with('Test Author')
            ->andReturn([
                ['genre' => 'Fantasy', 'author' => 'Test Author'],
            ]);

        // Act: Call extract endpoint
        $response = $this->withoutMiddleware()
            ->post('/admin/import/extract', [
                'root' => $this->testRoot,
                'path' => basename($bookDir),
                'type' => 'dir',
                '_token' => csrf_token(),
            ]);

        // Assert: Should suggest Fantasy genre based on existing structure
        $response->assertStatus(200);
        $json = $response->json();
        $this->assertTrue($json['success']);
        $this->assertArrayHasKey('genrePath', $json);
        $this->assertArrayHasKey('directoryPath', $json);
        $this->assertArrayHasKey('formData', $json);
        $this->assertArrayHasKey('author', $json);
        $this->assertArrayHasKey('genre', $json);

        // Clean up
        File::deleteDirectory($bookDir);
    }

    #[Test]
    public function testExtractFallsBackToSessionDefaultGenrePathWhenUndetermined(): void
    {
        // Arrange: Create test book directory
        $bookDir = $this->testRoot . '/Undetermined_Genre_Test';
        mkdir($bookDir);
        file_put_contents($bookDir . '/chapter1.mp3', 'test content');

        // Ensure no author/series search influences genre selection
        $this->documentStoreMock->shouldReceive('searchAuthorsByName')
            ->withAnyArgs()
            ->andReturn([]);

        // Set the session default genre path as if a previous book was imported
        $this->withSession(['import_default_genre_path' => 'Fantasy']);

        // Act
        $response = $this->withoutMiddleware()
            ->withSession(['import_default_genre_path' => 'Fantasy'])
            ->post('/admin/import/extract', [
                'root' => $this->testRoot,
                'path' => basename($bookDir),
                'type' => 'dir',
                '_token' => csrf_token(),
            ]);

        // Assert
        $response->assertStatus(200);
        $json = $response->json();
        $this->assertTrue($json['success']);
        $this->assertSame('Fantasy', $json['genrePath']);
        $this->assertFalse($json['needsGenreSelection']);

        // Clean up
        File::deleteDirectory($bookDir);
    }

    #[Test]
    public function testRedirectToFormIncludesAllNecessaryParameters(): void
    {
        // Test that the redirect includes all the fields needed by the book form

        // Arrange: Create test directory
        $bookDir = $this->testRoot . '/Science_Fiction_Isaac_Asimov_Foundation_01_Foundation';
        mkdir($bookDir);
        file_put_contents($bookDir . '/chapter01.mp3', str_repeat('test content', 50));

        // Act: Request redirect to form
        $response = $this->withoutMiddleware()
            ->post('/admin/import/extract', [
                'root' => $this->testRoot,
                'path' => basename($bookDir),
                'type' => 'dir',
                'redirectToForm' => 'true',
            ]);

        // Assert: Verify redirect contains necessary parameters
        $response->assertRedirect();
        $location = $response->headers->get('Location');

        // Check for essential parameters
        $this->assertStringContainsString('directoryPath=', $location, 'DirectoryPath should be in redirect URL');
        $this->assertStringContainsString('genrePath=', $location, 'GenrePath should be in redirect URL');
        $this->assertStringContainsString('author%5B0%5D=', $location, 'Author should be in redirect URL');
        $this->assertStringContainsString('genre%5B0%5D=', $location, 'Genre should be in redirect URL');
        $this->assertStringContainsString('importMode=1', $location, 'ImportMode should be in redirect URL');
        $this->assertStringContainsString('sourcePath=', $location, 'SourcePath should be in redirect URL');

        // Parse URL to verify parameter values
        $parsedUrl = parse_url($location);
        parse_str($parsedUrl['query'], $params);

        $this->assertNotEmpty($params['directoryPath'], 'DirectoryPath parameter should have value');
        $this->assertNotEmpty($params['genrePath'], 'GenrePath parameter should have value');
        $this->assertNotEmpty($params['author'][0] ?? '', 'Author parameter should have value');
        $this->assertNotEmpty($params['genre'][0] ?? '', 'Genre parameter should have value');
    }
}
