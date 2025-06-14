<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\MongoService;

class MongoTestCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mongo:test';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test MongoDB integration: insert a record into books and count records.';

    public function handle()
    {
        $mongo = new MongoService();
        $collection = $mongo->getCollection('books');

        $testBook = [
            '_id' => uniqid('test_', true),
            'title' => 'Test Book',
            'author' => 'Test Author',
            'createdAt' => now()->toIso8601String(),
        ];
        try {
            $insertResult = $collection->insertOne($testBook);
            $this->info('Inserted test book with _id: ' . $testBook['_id']);
        } catch (\Throwable $e) {
            $this->error('Insert failed: ' . $e->getMessage());
            return 1;
        }
        try {
            $count = $collection->countDocuments();
            $this->info('Total books in MongoDB: ' . $count);
        } catch (\Throwable $e) {
            $this->error('Count failed: ' . $e->getMessage());
            return 2;
        }
        return 0;
    }
}
