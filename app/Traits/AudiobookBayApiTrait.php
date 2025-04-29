<?php

namespace App\Traits;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

trait AudiobookBayApiTrait
{
    protected $audiobookBayCookie = null;

    protected function getAudiobookBayRateLimit(): int
    {
        return (int) (env('AUDIOBOOK_BAY_RATE_LIMIT', 100));
    }

    protected function getAudiobookBayUsername(): string
    {
        return env('AUDIOBOOK_BAY_USERNAME', '');
    }

    protected function getAudiobookBayPassword(): string
    {
        return env('AUDIOBOOK_BAY_PASSWORD', '');
    }

    protected function getAudiobookBayCookie(): ?string
    {
        // Cache the cookie for 1 hour
        return Cache::remember('audiobookbay_cookie', 3600, function () {
            return $this->audiobookBayLogin();
        });
    }

    protected function audiobookBayLogin(): ?string
    {
        $username = $this->getAudiobookBayUsername();
        $password = $this->getAudiobookBayPassword();
        $response = Http::asForm()->post('https://audiobookbay.lu/member/login.php', [
            'username' => $username,
            'password' => $password,
            'login' => 'Login',
        ]);
        if ($response->successful() && $response->cookies()->count() > 0) {
            // Return cookie header string
            return collect($response->cookies())->map(function ($cookie) {
                return $cookie->getName() . '=' . $cookie->getValue();
            })->implode('; ');
        }
        return null;
    }

    protected function checkAudiobookBayRateLimit(): void
    {
        $cacheKey = 'audiobookbay_query_count_' . date('YmdH');
        $count = Cache::get($cacheKey, 0);
        $limit = $this->getAudiobookBayRateLimit();
        if ($count >= $limit) {
            abort(429, 'AudiobookBay API rate limit exceeded.');
        }
        Cache::put($cacheKey, $count + 1, now()->addHour());
    }

    /**
     * Search AudiobookBay and return HTML (raw)
     * @param string $query
     * @return string|null
     */
    public function audiobookBaySearch(string $query): ?string
    {
        $this->checkAudiobookBayRateLimit();
        $cookie = $this->getAudiobookBayCookie();
        $response = Http::withHeaders([
            'Cookie' => $cookie,
            'User-Agent' => 'Mozilla/5.0',
        ])->get('https://audiobookbay.lu/?s=' . urlencode($query));
        if ($response->successful()) {
            return $response->body();
        }
        return null;
    }
}
