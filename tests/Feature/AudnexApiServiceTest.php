<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AudnexApiService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AudnexApiServiceTest extends TestCase
{
    private AudnexApiService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->service = new AudnexApiService();
    }

    #[Test]
    public function testGetBookByAsinNormalizesFields(): void
    {
        Http::fake([
            'api.audnex.us/books/B017V4IM1G*' => Http::response($this->getMockAudnexBook(), 200),
        ]);

        $result = $this->service->getBookByAsin('B017V4IM1G');

        $this->assertIsArray($result);
        $this->assertEquals('B017V4IM1G', $result['id']);
        $this->assertEquals('Harry Potter and the Sorcerer\'s Stone', $result['title']);
        $this->assertEquals(['J.K. Rowling'], $result['author']);
        $this->assertEquals(['Jim Dale'], $result['narratorsList']);
        $this->assertEquals('Harry Potter', $result['seriesName']);
        $this->assertEquals('1', $result['seriesNumber']);
        $this->assertContains('Fantasy', $result['category']);
        $this->assertEquals(498, $result['runtimeLengthMin']);
        $this->assertEquals(2015, $result['publishedYear']);
    }

    #[Test]
    public function testGetBookByAsinReturnsNullWhenNotFound(): void
    {
        Http::fake([
            'api.audnex.us/books/UNKNOWN*' => Http::response(null, 404),
        ]);

        $result = $this->service->getBookByAsin('UNKNOWN');

        $this->assertNull($result);
    }

    #[Test]
    public function testGetBookByAsinReturnsNullOnEmptyAsin(): void
    {
        $result = $this->service->getBookByAsin('');

        $this->assertNull($result);
    }

    #[Test]
    public function testConfirmedNotFoundResultIsCached(): void
    {
        Http::fake([
            'api.audnex.us/books/UNKNOWN*' => Http::response(null, 404),
        ]);

        $this->service->getBookByAsin('UNKNOWN');

        // Second call should be served from cache, not hit the HTTP layer again.
        Http::fake([
            'api.audnex.us/books/UNKNOWN*' => Http::response(['asin' => 'UNKNOWN', 'title' => 'Should not be seen'], 200),
        ]);

        $result = $this->service->getBookByAsin('UNKNOWN');

        $this->assertNull($result);
    }

    #[Test]
    public function testServerOutageIsNotCachedAndRetriesOnNextLookup(): void
    {
        // A single stateful fake: first call simulates an outage, second
        // call simulates audnex having recovered. Registering a second,
        // separate Http::fake() would not override the first — Laravel
        // stacks stub callbacks and the earliest match wins — so the
        // outage/recovery sequence has to live in one callback.
        $callCount = 0;
        Http::fake([
            'api.audnex.us/books/B017V4IM1G*' => function () use (&$callCount) {
                $callCount++;

                return $callCount === 1
                    ? Http::response(null, 503)
                    : Http::response($this->getMockAudnexBook(), 200);
            },
        ]);

        $duringOutage = $this->service->getBookByAsin('B017V4IM1G');
        $this->assertNull($duringOutage);

        // Since the outage was never cached, the very next lookup should
        // hit the API again and succeed.
        $afterRecovery = $this->service->getBookByAsin('B017V4IM1G');

        $this->assertIsArray($afterRecovery);
        $this->assertEquals('B017V4IM1G', $afterRecovery['id']);
        $this->assertEquals(2, $callCount);
    }

    #[Test]
    public function testConnectionExceptionIsNotCachedAndRetriesOnNextLookup(): void
    {
        $callCount = 0;
        Http::fake([
            'api.audnex.us/books/B017V4IM1G*' => function () use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    throw new \Illuminate\Http\Client\ConnectionException('Connection refused');
                }

                return Http::response($this->getMockAudnexBook(), 200);
            },
        ]);

        $duringOutage = $this->service->getBookByAsin('B017V4IM1G');
        $this->assertNull($duringOutage);

        $afterRecovery = $this->service->getBookByAsin('B017V4IM1G');

        $this->assertIsArray($afterRecovery);
        $this->assertEquals('B017V4IM1G', $afterRecovery['id']);
        $this->assertEquals(2, $callCount);
    }

    #[Test]
    public function testDisabledViaConfigReturnsNullWithoutHttpCall(): void
    {
        Http::preventStrayRequests();

        $service = new AudnexApiService(['enabled' => false]);

        $result = $service->getBookByAsin('B017V4IM1G');

        $this->assertNull($result);
    }

    private function getMockAudnexBook(): array
    {
        return [
            'asin' => 'B017V4IM1G',
            'title' => 'Harry Potter and the Sorcerer\'s Stone',
            'authors' => [['asin' => 'B000AP9A6K', 'name' => 'J.K. Rowling']],
            'narrators' => [['name' => 'Jim Dale']],
            'seriesPrimary' => ['name' => 'Harry Potter', 'position' => '1'],
            'genres' => [
                ['name' => 'Fantasy', 'type' => 'genre'],
                ['name' => 'Children\'s Audiobooks', 'type' => 'genre'],
            ],
            'summary' => '<p>Jim Dale\'s Grammy Award-winning performance.</p>',
            'publisherName' => 'Pottermore Publishing',
            'releaseDate' => '2015-11-20T00:00:00.000Z',
            'runtimeLengthMin' => 498,
            'image' => 'https://m.media-amazon.com/images/I/91eopoUCjLL.jpg',
            'language' => 'english',
        ];
    }
}
