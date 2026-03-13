<?php

namespace Tests\Feature\Admin;

use App\Contracts\DocumentStoreServiceInterface;
use App\Http\Controllers\Admin\BookAutocompleteController;
use Illuminate\Http\Request;
use Mockery;
use Tests\TestCase;

class BookControllerSeriesAutocompleteTest extends TestCase
{
    /**
     * Test the autocompleteSeries method directly.
     */
    public function testAutocompleteSeries()
    {
        // Create a mock of DocumentStoreServiceInterface
        /** @var DocumentStoreServiceInterface|\Mockery\MockInterface $mockDocumentStore */
        $mockDocumentStore = Mockery::mock(DocumentStoreServiceInterface::class);
        $mockDocumentStore->shouldReceive('searchSeriesByName')
            ->with('test')
            ->once()
            ->andReturn([
                ['seriesName' => 'Test Series 1'],
                ['seriesName' => 'Test Series 2'],
                ['seriesName' => 'Another Test Series'],
            ]);

        // Create a controller instance with the mock
        // Use type-hinting to ensure the mock is treated as the correct interface
        /** @var DocumentStoreServiceInterface $documentStore */
        $documentStore = $mockDocumentStore;
        $controller = new BookAutocompleteController($documentStore);

        // Create a request with the query parameter
        $request = new Request(['query' => 'test']);

        // Call the method
        $response = $controller->autocompleteSeries($request);

        // Assert that the response contains the expected data
        $this->assertEquals([
            'data' => [
                'Test Series 1',
                'Test Series 2',
                'Another Test Series',
            ],
        ], $response->getData(true));
    }
}
