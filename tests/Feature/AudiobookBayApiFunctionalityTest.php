<?php

namespace Tests\Feature;

use App\Traits\AudiobookBayApiTrait;
use App\Traits\BaseApiTrait;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class AudiobookBayApiFunctionalityTest extends TestCase
{
    use BaseApiTrait;
    use AudiobookBayApiTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAudiobookBay(); // Initialize AudiobookBayApiTrait

        Http::fake();
        Cache::forget('audiobookbay_rate_limit');
        Log::spy();
        putenv('AUDIOBOOK_BAY_USERNAME=testuser');
        putenv('AUDIOBOOK_BAY_PASSWORD=testpass');
        Cache::forget('audiobookbay_cookie');
    }

    /** @test */
    public function placeholderTestForApiTrait()
    {
        $this->assertTrue(true);
    }
}
