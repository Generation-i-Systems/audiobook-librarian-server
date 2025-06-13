<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Google\Cloud\Firestore\FirestoreClient;

class FirestoreBooksDump extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'firestore:books-dump {--output= : Output file (default: stdout)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dump the entire Firestore books collection as JSON for MongoDB import';

    public function handle()
    {
        // Use FirestoreService logic for consistent auth
        $books = \App\Services\FirestoreService::dumpAllBooks();
        if (isset($books['error'])) {
            $this->error('Firestore error: ' . $books['error']);
            return 1;
        }
        // Convert 'id' to '_id' for MongoDB
        $books = array_map(function ($b) {
            if (isset($b['id'])) {
                $b['_id'] = $b['id'];
                unset($b['id']);
            }
            return $b;
        }, $books);
        $json = json_encode($books, JSON_PRETTY_PRINT) . "\n";
        $output = $this->option('output');
        if ($output) {
            file_put_contents($output, $json);
            $this->info("Exported " . count($books) . " books to $output.");
        } else {
            $this->line($json);
            $this->info("Exported " . count($books) . " books to stdout.");
        }
        return 0;
    }
}
