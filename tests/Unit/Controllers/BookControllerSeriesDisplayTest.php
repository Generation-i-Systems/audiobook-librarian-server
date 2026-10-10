<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use App\Contracts\DocumentStoreServiceInterface;
use App\Http\Controllers\BookController;
use App\Services\BookTagService;
use App\Services\GoogleBooksApiService;
use App\Services\Search\SemanticBookSearchService;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The /books index view renders series as "Name (Book N)" from seriesName/number keys,
 * so ensureBookFieldsMinimal() must normalize every series shape listBooks() can return.
 */
class BookControllerSeriesDisplayTest extends TestCase
{
    public static function seriesShapes(): array
    {
        return [
            'listBooks relation shape' => [
                [['name' => 'Warriors', 'series_number' => '4', 'is_collection' => false]],
                [['seriesName' => 'Warriors', 'number' => '4']],
            ],
            'decimal series number' => [
                [['name' => 'Warriors', 'series_number' => '4.5', 'is_collection' => false]],
                [['seriesName' => 'Warriors', 'number' => '4.5']],
            ],
            'pivot shape' => [
                [['name' => 'Warriors', 'pivot' => ['series_number' => '2']]],
                [['seriesName' => 'Warriors', 'number' => '2']],
            ],
            'already normalized' => [
                [['seriesName' => 'Warriors', 'number' => 3]],
                [['seriesName' => 'Warriors', 'number' => 3]],
            ],
            'no series number' => [
                [['name' => 'Warriors', 'series_number' => null]],
                [['seriesName' => 'Warriors', 'number' => null]],
            ],
            'every series kept' => [
                [
                    ['name' => 'Warriors', 'series_number' => '1'],
                    ['name' => 'Omnibus', 'series_number' => '7'],
                ],
                [['seriesName' => 'Warriors', 'number' => '1'], ['seriesName' => 'Omnibus', 'number' => '7']],
            ],
            'nameless entry dropped' => [
                [['series_number' => '1']],
                [],
            ],
        ];
    }

    #[Test]
    #[DataProvider('seriesShapes')]
    public function ensureBookFieldsMinimalNormalizesSeriesForDisplay(array $input, array $expected): void
    {
        $this->assertSame($expected, $this->invokeOnController('ensureBookFieldsMinimal', $input));
    }

    /**
     * ensureBookFields() feeds /api/books/json, which the /books page uses to (re)render its list.
     */
    #[Test]
    public function ensureBookFieldsKeepsListBooksSeriesShapeForJsonIndex(): void
    {
        $input = [
            ['name' => 'Warriors', 'series_number' => '4', 'is_collection' => false],
            ['name' => 'Omnibus', 'series_number' => null, 'is_collection' => true],
        ];

        $this->assertSame(
            [
                ['seriesName' => 'Warriors', 'number' => '4'],
                ['seriesName' => 'Omnibus', 'number' => null],
            ],
            $this->invokeOnController('ensureBookFields', $input)
        );
    }

    private function invokeOnController(string $methodName, array $series): array
    {
        $controller = new BookController(
            Mockery::mock(DocumentStoreServiceInterface::class),
            Mockery::mock(GoogleBooksApiService::class),
            Mockery::mock(BookTagService::class),
            Mockery::mock(SemanticBookSearchService::class)
        );

        $method = (new \ReflectionClass($controller))->getMethod($methodName);

        /** @var array $result */
        $result = $method->invoke($controller, ['id' => '1', 'title' => 'Test', 'series' => $series]);

        return $result['series'];
    }
}
