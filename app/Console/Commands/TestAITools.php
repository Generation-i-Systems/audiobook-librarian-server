<?php

namespace App\Console\Commands;

use App\Services\AI\AIToolService;
use Illuminate\Console\Command;

class TestAITools extends Command
{
    protected $signature = 'ai:test-tools {query? : The query to test}';
    protected $description = 'Test the AI tool-based query system';

    public function handle()
    {
        $query = $this->argument('query');

        if (!$query) {
            $query = $this->choice(
                'Select a test query:',
                [
                    'Find all series with more than 10 books',
                    'Show books in the Deathlands series',
                    'Find missing books in Foundation series',
                    'List all Science Fiction books by Isaac Asimov',
                    'Custom query (enter manually)',
                ],
                0
            );

            if ($query === 'Custom query (enter manually)') {
                $query = $this->ask('Enter your query:');
            }
        }

        $this->info("Processing query: {$query}");
        $this->info('');

        try {
            $service = new AIToolService('gemini-2.5-flash');
            $service->setMaxIterations(15);

            $result = $service->processQuery($query);

            if (!$result['success']) {
                $this->error('Query failed: ' . $result['error']);
                return 1;
            }

            $this->line('');
            $this->info('=== Response ===');
            $this->line($result['response']);
            $this->line('');
            $this->info("Iterations: {$result['iterations']}");

            if ($this->option('verbose')) {
                $this->line('');
                $this->info('=== Conversation History ===');
                foreach ($result['conversation'] as $index => $turn) {
                    $this->line("Turn " . ($index + 1) . " [{$turn['role']}]:");
                    $this->line(json_encode($turn, JSON_PRETTY_PRINT));
                    $this->line('');
                }
            }

            return 0;
        } catch (\Exception $e) {
            $this->error('Error: ' . $e->getMessage());
            if ($this->option('verbose')) {
                $this->line($e->getTraceAsString());
            }
            return 1;
        }
    }
}
