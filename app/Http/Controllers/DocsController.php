<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class DocsController extends Controller
{
    protected $docsPath;

    public function __construct()
    {
        $this->docsPath = base_path('docs');
    }

    public function index()
    {
        return $this->renderMarkdown('index.md');
    }

    public function show(Request $request, $path = null)
    {
        // Handle different path formats
        $fullPath = $path ? $path : 'index.md';

        // Add .md extension if not present
        if (!Str::endsWith($fullPath, '.md')) {
            $fullPath .= '.md';
        }

        return $this->renderMarkdown($fullPath);
    }

    public function openapi()
    {
        $openApiPath = base_path('docs/openapi.json');

        if (!File::exists($openApiPath)) {
            abort(404, 'OpenAPI specification not found');
        }

        $content = File::get($openApiPath);

        return response($content, 200, [
            'Content-Type' => 'application/json',
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization',
            'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS'
        ]);
    }

    protected function renderMarkdown($relativePath)
    {
        $fullPath = $this->docsPath . '/' . $relativePath;

        if (!File::exists($fullPath)) {
            abort(404, 'Documentation file not found');
        }

        $realBase = realpath($this->docsPath);
        $realPath = realpath($fullPath);
        if ($realPath === false || $realBase === false || !str_starts_with($realPath, $realBase . DIRECTORY_SEPARATOR)) {
            abort(404, 'Documentation file not found');
        }

        $content = File::get($fullPath);

        // Simple markdown to HTML conversion for basic formatting
        $html = $this->convertMarkdownToHtml($content);

        return view('docs.viewer', [
            'content' => $html,
            'title' => $this->extractTitle($content),
            'path' => $relativePath
        ]);
    }

    protected function convertMarkdownToHtml($markdown)
    {
        // Basic markdown conversion - you could use a proper markdown parser like Parsedown
        $html = $markdown;

        // Headers
        $html = preg_replace('/^### (.+)$/m', '<h3>$1</h3>', $html);
        $html = preg_replace('/^## (.+)$/m', '<h2>$1</h2>', $html);
        $html = preg_replace('/^# (.+)$/m', '<h1>$1</h1>', $html);

        // Bold and italic
        $html = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html);
        $html = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $html);

        // Links
        $html = preg_replace('/\[(.+?)\]\((.+?)\)/', '<a href="$2">$1</a>', $html);

        // Code blocks
        $html = preg_replace('/```(\w+)?\n(.*?)```/s', '<pre><code class="language-$1">$2</code></pre>', $html);
        $html = preg_replace('/`(.+?)`/', '<code>$1</code>', $html);

        // Lists
        $html = preg_replace('/^- (.+)$/m', '<li>$1</li>', $html);
        $html = preg_replace('/(<li>.*<\/li>)/s', '<ul>$1</ul>', $html);

        // Paragraphs
        $html = preg_replace('/\n\n/', '</p><p>', $html);
        $html = '<p>' . $html . '</p>';

        // Clean up
        $html = str_replace('<p></p>', '', $html);
        $html = str_replace('<p><h', '<h', $html);
        $html = str_replace('</h1></p>', '</h1>', $html);
        $html = str_replace('</h2></p>', '</h2>', $html);
        $html = str_replace('</h3></p>', '</h3>', $html);
        $html = str_replace('<p><ul>', '<ul>', $html);
        $html = str_replace('</ul></p>', '</ul>', $html);
        $html = str_replace('<p><pre>', '<pre>', $html);
        $html = str_replace('</pre></p>', '</pre>', $html);

        return $html;
    }

    protected function extractTitle($content)
    {
        if (preg_match('/^# (.+)$/m', $content, $matches)) {
            return $matches[1];
        }

        return 'Documentation';
    }
}
