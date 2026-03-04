<?php

declare(strict_types=1);

namespace App\Services;

class BookMetadataScraperService
{
    /**
     * Fetch author, description, and cover from Goodreads.
     * Searches for $searchTitle, follows the first book result, and parses
     * the __NEXT_DATA__ JSON blob embedded in the Next.js SSR page.
     *
     * @return array{author?: string[], description?: string, coverImageUrl?: string}|null
     */
    public function fetchFromGoodreads(string $searchTitle): ?array
    {
        $searchUrl = 'https://www.goodreads.com/search?q=' . urlencode($searchTitle);

        $searchHtml = $this->curlGet($searchUrl);
        if ($searchHtml === null) {
            return null;
        }

        if (!preg_match('|/book/show/(\d+[^"\'&\s]*)|', $searchHtml, $m)) {
            return null;
        }

        $bookHtml = $this->curlGet('https://www.goodreads.com/book/show/' . $m[1]);
        if ($bookHtml === null) {
            return null;
        }

        if (!preg_match('/<script id="__NEXT_DATA__" type="application\/json">(.*?)<\/script>/s', $bookHtml, $nd)) {
            return null;
        }

        $nextData = json_decode($nd[1], true);
        if (!is_array($nextData)) {
            return null;
        }

        $apollo = $nextData['props']['pageProps']['apolloState'] ?? [];
        $result = [];

        foreach ($apollo as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            if (($entry['__typename'] ?? '') === 'Book' && empty($result['coverImageUrl'])) {
                if (!empty($entry['imageUrl'])) {
                    $result['coverImageUrl'] = $entry['imageUrl'];
                }
                if (!empty($entry['description'])) {
                    $result['description'] = trim(strip_tags($entry['description']));
                }
            }

            if (($entry['__typename'] ?? '') === 'Contributor' && empty($result['author']) && !empty($entry['name'])) {
                $name = preg_replace('/\s+/', ' ', trim($entry['name']));
                if ($name !== '') {
                    $result['author'] = [$name];
                }
            }
        }

        return !empty($result) ? $result : null;
    }

    /**
     * Fetch author, description, and cover from Amazon book search + product page.
     * Searches for $searchTitle in the Books department, takes the first ASIN,
     * and scrapes the product page for structured metadata.
     *
     * @return array{author?: string[], description?: string, coverImageUrl?: string}|null
     */
    public function fetchFromAmazon(string $searchTitle): ?array
    {
        $searchUrl = 'https://www.amazon.com/s?k=' . urlencode($searchTitle) . '&i=stripbooks';

        $searchHtml = $this->curlGet($searchUrl);
        if ($searchHtml === null) {
            return null;
        }

        if (!preg_match('/data-asin="([A-Z0-9]{10})"/', $searchHtml, $m)) {
            return null;
        }

        $productHtml = $this->curlGet('https://www.amazon.com/dp/' . $m[1]);
        if ($productHtml === null) {
            return null;
        }

        $result = [];

        // Cover — prefer high-res from image data JSON, fall back to landing/front image
        if (preg_match('#"large"\s*:\s*"(https://[^"]+\.jpg)"#', $productHtml, $cm)) {
            $result['coverImageUrl'] = $cm[1];
        } elseif (preg_match('#id="landingImage"[^>]+src="(https://[^"]+)"#', $productHtml, $cm)) {
            $result['coverImageUrl'] = html_entity_decode($cm[1]);
        } elseif (preg_match('#id="imgBlkFront"[^>]+src="(https://[^"]+)"#', $productHtml, $cm)) {
            $result['coverImageUrl'] = html_entity_decode($cm[1]);
        }

        // Author — byline contributor link
        if (preg_match('/class="[^"]*contributorNameID[^"]*"[^>]*>([^<]+)</', $productHtml, $am)) {
            $result['author'] = [preg_replace('/\s+/', ' ', trim(html_entity_decode($am[1])))];
        } elseif (preg_match('/id="bylineInfo"[^>]*>.*?<a[^>]+>([^<]+)<\/a>/s', $productHtml, $am)) {
            $name = preg_replace('/\s+/', ' ', trim(html_entity_decode($am[1])));
            if (!empty($name)) {
                $result['author'] = [$name];
            }
        }

        // Description — book description div
        if (preg_match('/<div[^>]+id="bookDescription_feature_div"[^>]*>.*?<noscript[^>]*>(.*?)<\/noscript>/s', $productHtml, $dm)) {
            $result['description'] = trim(strip_tags(html_entity_decode($dm[1])));
        } elseif (preg_match('/<div[^>]+class="[^"]*a-expander-content[^"]*"[^>]*>(.*?)<\/div>/s', $productHtml, $dm)) {
            $desc = trim(strip_tags(html_entity_decode($dm[1])));
            if (strlen($desc) > 50) {
                $result['description'] = $desc;
            }
        }

        return !empty($result) ? $result : null;
    }

    /**
     * Perform a GET request via curl with a browser User-Agent.
     * Returns the response body or null on failure.
     */
    public function curlGet(string $url): ?string
    {
        if (!function_exists('curl_init')) {
            return null;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => ['Accept-Language: en-US,en;q=0.9'],
        ]);

        $body = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false || $error !== '') {
            return null;
        }

        return $body;
    }
}
