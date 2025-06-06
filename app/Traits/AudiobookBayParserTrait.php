<?php

// AudiobookBayParserTrait removed. All logic integrated into AudiobookBayApiService.

namespace App\Traits;

use DOMDocument;
use DOMXPath;
use Exception;
use Illuminate\Support\Str;

/**
 * Trait to parse audiobook metadata from AudiobookBay HTML page
 */
trait AudiobookBayParserTrait
{
    use BaseParserTrait;

    /**
     * Parse search results from AudiobookBay HTML
     */
    public function parseSearchResults(string $html): array
    {
        $dom = new DOMDocument();
        @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        $xpath = new DOMXPath($dom);

        $results = [];
        $items = $xpath->query('//div[contains(@class, "post")]');

        foreach ($items as $item) {
            $result = [
                'title' => '',
                'authors' => [],
                'narrators' => [],
                'description' => '',
                'cover_image_url' => '',
                'url' => '',
                'metadata' => [
                    'source' => 'audiobookbay',
                ],
            ];

            // Extract title and URL
            $titleNode = $xpath->query('.//div[contains(@class, "postTitle")]//a', $item)->item(0);
            if ($titleNode instanceof \DOMElement && $titleNode->hasAttribute('href')) {
                $result['title'] = trim($titleNode->textContent);
                $result['url'] = 'https://audiobookbay.lu' . $titleNode->getAttribute('href');
            }

            // Extract cover image
            $imgNode = $xpath->query('.//div[contains(@class, "postImg")]//img', $item)->item(0);
            if ($imgNode instanceof \DOMElement && $imgNode->hasAttribute('src')) {
                $result['cover_image_url'] = $imgNode->getAttribute('src');
            }

            // Extract author
            $authorNode = $xpath->query('.//div[contains(@class, "postAuthor")]//a', $item)->item(0);
            if ($authorNode instanceof \DOMNode) {
                $result['authors'][] = [
                    'name' => trim($authorNode->textContent),
                ];
            }

            // Extract description
            $descNode = $xpath->query('.//div[contains(@class, "postContent")]', $item)->item(0);
            if ($descNode instanceof \DOMNode) {
                $result['description'] = trim($descNode->textContent);
            }

            // Extract metadata from info section
            $infoNodes = $xpath->query('.//div[contains(@class, "postInfo")]//li', $item);
            foreach ($infoNodes as $infoNode) {
                $text = trim($infoNode->textContent);

                // Extract narrator
                if (Str::startsWith($text, 'Read by:')) {
                    $result['narrators'][] = [
                        'name' => trim(Str::after($text, 'Read by:')),
                    ];
                }

                // Extract categories
                if (Str::startsWith($text, 'Category:')) {
                    $result['metadata']['categories'] = array_map(
                        'trim',
                        explode(',', Str::after($text, 'Category:'))
                    );
                }

                // Extract language
                if (Str::startsWith($text, 'Language:')) {
                    $result['language'] = trim(Str::after($text, 'Language:'));
                }

                // Extract format
                if (Str::startsWith($text, 'Format:')) {
                    $result['metadata']['format'] = trim(Str::after($text, 'Format:'));
                }

                // Extract size
                if (Str::startsWith($text, 'Size:')) {
                    $result['metadata']['size'] = trim(Str::after($text, 'Size:'));
                }

                // Extract bitrate
                if (preg_match('/(\d+\s*kbps)/i', $text, $matches)) {
                    $result['metadata']['bitrate'] = $matches[1];
                }
            }

            if (!empty($result['title'])) {
                $results[] = $result;
            }
        }

        return $results;
    }

    /**
     * Parse audiobook details from AudiobookBay HTML
     */
    public function parseAudiobookDetails(string $html): array
    {
        $dom = new DOMDocument();
        @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        $xpath = new DOMXPath($dom);

        $book = [
            'title' => '',
            'authors' => [],
            'narrators' => [],
            'description' => '',
            'cover_image_url' => '',
            'metadata' => [
                'source' => 'audiobookbay',
                'categories' => [],
            ],
        ];

        // Extract title
        $titleNode = $xpath->query('//h1[@itemprop="name"]')->item(0);
        if ($titleNode instanceof \DOMNode) {
            $book['title'] = trim($titleNode->textContent);

            // Try to extract series info from title (e.g., "Series Name, Book 1 - Book Title")
            if (preg_match('/^(.*?),\s*(?:Book|Vol(?:\.|ume)?)\s*(\d+)(?:\s*-\s*(.*))?$/i', $book['title'], $matches)) {
                $book['series'] = [
                    'name' => trim($matches[1]),
                    'number' => $matches[2],
                ];
                $book['title'] = trim($matches[3] ?? $book['title']);
            }
        }

        // Extract cover image
        $imgNode = $xpath->query('//div[contains(@class, "book-page-cover")]//img')->item(0);
        if ($imgNode instanceof \DOMElement && $imgNode->hasAttribute('src')) {
            $book['cover_image_url'] = $imgNode->getAttribute('src');
        }

        // Extract description
        $descriptionNode = $xpath->query('//div[contains(@class, "book-page-description")]')->item(0);
        if ($descriptionNode instanceof \DOMNode) {
            $book['description'] = trim($descriptionNode->textContent);
        }

        // Extract metadata from info section
        $metadataNodes = $xpath->query('//div[contains(@class, "book-page-meta")]//div[contains(@class, "row")]');
        foreach ($metadataNodes as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }

            // Get the text content of the node
            $labelNode = $xpath->query('.//div[contains(@class, "label")]', $node)->item(0);
            $valueNode = $xpath->query('.//div[contains(@class, "value")]', $node)->item(0);

            if ($labelNode instanceof \DOMNode && $valueNode instanceof \DOMNode) {
                $label = trim($labelNode->textContent, ": \t\n\r\0\x0B");
                $value = trim($valueNode->textContent);

                switch (strtolower($label)) {
                    case 'author':
                    case 'authors':
                        $book['authors'][] = ['name' => $value];
                        break;
                    case 'narrator':
                    case 'narrators':
                        $book['narrators'][] = ['name' => $value];
                        break;
                    case 'published':
                    case 'published date':
                        if (($timestamp = strtotime($value)) !== false) {
                            $book['published_date'] = date('Y-m-d', $timestamp);
                        }
                        break;
                    case 'publisher':
                        $book['publisher'] = $value;
                        break;
                    case 'category':
                    case 'categories':
                        $book['metadata']['categories'] = array_map('trim', explode(',', $value));
                        break;
                    case 'format':
                        $book['metadata']['format'] = $value;
                        break;
                    case 'size':
                        $book['metadata']['size'] = $value;
                        break;
                    case 'bitrate':
                    case 'bit rate':
                        $book['metadata']['bitrate'] = $value;
                        break;
                    case 'language':
                        $book['language'] = $value;
                        break;
                    default:
                        $book['metadata'][strtolower($label)] = $value;
                }
            }
        }

        // Extract download links
        $downloadNodes = $xpath->query('//div[contains(@class, "download-links")]//a');
        $downloads = [];
        foreach ($downloadNodes as $node) {
            if ($node instanceof \DOMElement && $node->hasAttribute('href')) {
                $downloads[] = [
                    'url' => 'https://audiobookbay.lu' . $node->getAttribute('href'),
                    'text' => trim($node->textContent),
                ];
            }
        }

        if (!empty($downloads)) {
            $book['metadata']['downloads'] = $downloads;
        }

        return $book;
    }
}
