<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\MongoService;
use App\Contracts\DocumentStoreServiceInterface;
use Illuminate\Support\Facades\Log;

class MongoTestCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mongo:test';
    protected DocumentStoreServiceInterface $mongoService;

    public function __construct(DocumentStoreServiceInterface $mongoService)
    {
        parent::__construct();
        $this->mongoService = $mongoService;
    }

    public function handle()
    {
        Log::debug('MongoTestCommand: handle() method started.');
        $this->error('MongoTestCommand is currently disabled.');
        $this->info('This command can be re-enabled when MongoDB testing is needed.');
        return 1;
        $collection = $this->mongoService->getCollection('books');

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
