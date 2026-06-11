<?php

namespace App\Console\Commands;

use App\Services\HardcoverApiService;
use Illuminate\Console\Command;

class TestHardcoverApi extends Command
{
    protected $signature = 'hardcover:test
        {--title= : Book title to search for}
        {--author= : Author name}
        {--id= : Skip search and fetch details directly by book ID}';

    protected $description = 'Test Hardcover API search and book details retrieval';

    public function handle(HardcoverApiService $hardcover): int
    {
        $title = $this->option('title');
        $author = $this->option('author');
        $id = $this->option('id');

        if (!$title && !$id) {
            $this->error('Provide --title or --id');
            return 1;
        }

        if ($id) {
            $this->line("--- getBookDetails(id={$id}) ---");
            $details = $hardcover->getBookDetails($id);
            $this->line(json_encode($details, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return 0;
        }

        $this->line("--- searchBooksByTitle('{$title}'" . ($author ? ", '{$author}'" : '') . ") ---");
        $results = $hardcover->searchBooksByTitle($title, 3, $author);

        if (!$results) {
            $this->error('No results returned.');
            return 1;
        }

        foreach ($results as $i => $book) {
            $this->line("\nHit {$i}: id={$book['id']} title=" . ($book['title'] ?? '?'));
        }

        $bestId = $results[0]['id'] ?? null;
        if (!$bestId) {
            $this->error('First result has no id.');
            return 1;
        }

        $this->line("\n--- getBookDetails(id={$bestId}) ---");
        $details = $hardcover->getBookDetails($bestId);
        $this->line(json_encode($details, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->line("\n--- searchAndMerge result ---");
        $merged = $hardcover->searchAndMerge([
            'title' => $title,
            'authors' => $author ? [$author] : [],
        ]);
        $this->line(json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return 0;
    }
}
