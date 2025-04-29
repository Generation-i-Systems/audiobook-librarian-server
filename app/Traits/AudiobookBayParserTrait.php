<?php

namespace App\Traits;

/**
 * Trait to parse audiobook metadata from AudiobookBay HTML page with rate limiting.
 */
trait AudiobookBayParserTrait
{
    // Simple static rate limiter (per-process)
    protected static $lastRequestTime = 0;
    protected static $minIntervalSeconds = 2; // 2 seconds between requests

    /**
     * Parse AudiobookBay HTML and extract metadata fields.
     * @param string $html
     * @return array
     */
    public function parseAudiobookBayPage($html)
    {
        // Rate limiting
        $now = microtime(true);
        if (self::$lastRequestTime && ($now - self::$lastRequestTime) < self::$minIntervalSeconds) {
            $sleep = self::$minIntervalSeconds - ($now - self::$lastRequestTime);
            usleep($sleep * 1_000_000);
        }
        self::$lastRequestTime = microtime(true);

        $fields = [
            'title' => null,
            'datePublished' => null,
            'author' => null,
            'narrator' => null,
            'series' => null,
            'seriesNumber' => null,
            'description' => null,
            'cover_image' => null,
            'category' => [],
            'keywords' => [],
        ];

        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);

        // Title: <title>...</title> or <h1 itemprop="name">...</h1>
        $titleNode = $xpath->query('//h1[@itemprop="name"]')->item(0);
        $fields['title'] = $titleNode ? trim($titleNode->textContent) : null;
        if (!$fields['title']) {
            $titleTag = $xpath->query('//title')->item(0);
            if ($titleTag) {
                $fields['title'] = trim($titleTag->textContent);
            }
        }

        // datePublished: <meta itemprop="datePublished" content="YYYY-MM-DD">
        $dateNode = $xpath->query('//meta[@itemprop="datePublished"]')->item(0);
        if ($dateNode && $dateNode instanceof \DOMElement && $dateNode->hasAttribute('content')) {
            $fields['datePublished'] = $dateNode->getAttribute('content');
        }

        // author & narrator: in .desc or .postContent
        $descNode = $xpath->query('//div[contains(@class,"desc")]')->item(0);
        if ($descNode) {
            $descHtml = $dom->saveHTML($descNode);
            // Written by
            if (preg_match('/Written by\s*<a[^>]*><span[^>]*>(.*?)<\/span><\/a>/i', $descHtml, $m)) {
                $fields['author'] = trim($m[1]);
            }
            // Read by
            if (preg_match('/Read by\s*<a[^>]*><span[^>]*>(.*?)<\/span><\/a>/i', $descHtml, $m)) {
                $fields['narrator'] = trim($m[1]);
            }
            // Format (series/seriesNumber sometimes in title, not always in HTML)
        }

        // Series/SeriesNumber: Try to extract from title
        if ($fields['title'] && preg_match('/(.*)\s*[:-]\s*(.*?)\s*,\s*Book\s*(\d+)/i', $fields['title'], $m)) {
            $fields['series'] = trim($m[2]);
            $fields['seriesNumber'] = trim($m[3]);
        }

        // Description: try meta[name=description], then .desc
        $metaDesc = $xpath->query('//meta[@name="description"]')->item(0);
        if ($metaDesc && $metaDesc instanceof \DOMElement && $metaDesc->hasAttribute('content')) {
            $fields['description'] = trim($metaDesc->getAttribute('content'));
        } elseif ($descNode) {
            $fields['description'] = trim(strip_tags($descNode->textContent));
        }

        // Cover image: <img ... itemprop="image" ...>
        $imgNode = $xpath->query('//img[@itemprop="image"]')->item(0);
        if ($imgNode && $imgNode instanceof \DOMElement && $imgNode->hasAttribute('src')) {
            $fields['cover_image'] = $imgNode->getAttribute('src');
        }

        // Category: in .postInfo (Category: ...)
        $postInfo = $xpath->query('//div[contains(@class,"postInfo")]')->item(0);
        if ($postInfo) {
            $cats = [];
            foreach ($xpath->query('.//a[contains(@href,"/audio-books/type/")]', $postInfo) as $catNode) {
                $cats[] = trim($catNode->textContent);
            }
            $fields['category'] = $cats;
        }

        // Keywords: <meta name="keywords">
        $metaKeywords = $xpath->query('//meta[@name="keywords"]')->item(0);
        if ($metaKeywords && $metaKeywords instanceof \DOMElement && $metaKeywords->hasAttribute('content')) {
            $fields['keywords'] = array_map('trim', explode(',', $metaKeywords->getAttribute('content')));
        }

        return $fields;
    }
}
