<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\BookApiController;
use App\Contracts\DocumentStoreServiceInterface;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class BookApiControllerGenreTest extends TestCase
{
    /**
     * Test that normalizeGenre correctly handles string input
     */
    public function test_normalize_genre_with_string()
    {
        $controller = $this->getControllerInstance();
        $method = $this->getPrivateMethod($controller, 'normalizeGenre');

        $result = $method->invoke($controller, 'Science Fiction');

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertEquals('Science Fiction', $result[0]);
    }

    /**
     * Test that normalizeGenre correctly handles array input
     */
    public function test_normalize_genre_with_array()
    {
        $controller = $this->getControllerInstance();
        $method = $this->getPrivateMethod($controller, 'normalizeGenre');

        $result = $method->invoke($controller, ['Fantasy', 'Adventure']);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertContains('Fantasy', $result);
        $this->assertContains('Adventure', $result);
    }

    /**
     * Test that normalizeGenre correctly handles array with empty values
     */
    public function test_normalize_genre_with_array_containing_empty_values()
    {
        $controller = $this->getControllerInstance();
        $method = $this->getPrivateMethod($controller, 'normalizeGenre');

        $result = $method->invoke($controller, ['Fantasy', '', '  ', 'Adventure']);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertContains('Fantasy', $result);
        $this->assertContains('Adventure', $result);
    }

    /**
     * Test that normalizeGenre correctly handles null input
     */
    public function test_normalize_genre_with_null()
    {
        $controller = $this->getControllerInstance();
        $method = $this->getPrivateMethod($controller, 'normalizeGenre');

        $result = $method->invoke($controller, null);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Helper method to create a controller instance with a mock DocumentStoreService
     */
    private function getControllerInstance()
    {
        $documentStoreMock = $this->createStub(DocumentStoreServiceInterface::class);
        return new BookApiController($documentStoreMock);
    }

    /**
     * Helper method to access private methods for testing
     */
    private function getPrivateMethod($object, $methodName)
    {
        $reflection = new ReflectionClass(get_class($object));
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);
        return $method;
    }
}
