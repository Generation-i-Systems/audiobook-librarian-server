<?php

namespace App\Console\Commands;

use App\Contracts\DocumentStoreServiceInterface;
use App\Services\MongoService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class FirestoreBooksDump extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'firestore:books-dump
        {--output= : Output file (default: stdout)}
        {--import-to-mongo : Import directly into MongoDB using .env credentials}
        {--collection= : Firestore/MongoDB collection name (default: books)}
        {--one-by-one : Export/import one record at a time}
        {--direction=firestore-to-mongo : Sync direction (firestore-to-mongo|mongo-to-firestore)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dump the entire Firestore books collection as JSON for MongoDB import';

    protected DocumentStoreServiceInterface $documentStoreService;

    protected MongoService $mongoService;

    public function __construct(DocumentStoreServiceInterface $documentStoreService, MongoService $mongoService)
    {
        parent::__construct();

        $this->documentStoreService = $documentStoreService;
    }

    public function handle()
    {
        Log::debug('FirestoreBooksDump: Instantiating MongoService in constructor');
        $this->mongoService = app(MongoService::class);

        $collectionName = $this->option('collection') ?: 'books';
        $oneByOne = $this->option('one-by-one') ? true : false;
        $output = $this->option('output');
        $direction = $this->option('direction') ?: 'firestore-to-mongo';

        if ($direction === 'firestore-to-mongo') {
            $docs = $this->documentStoreService->listDocuments($collectionName);
            $docs = array_map(function ($doc) {
                if (isset($doc['id'])) {
                    $doc['_id'] = $doc['id'];
                    unset($doc['id']);
                }

                return $doc;
            }, $docs);
            if ($this->option('import-to-mongo')) {
                if (!$this->mongoService) {
                    $this->error('MongoDB service is not configured. Cannot import to MongoDB.');
                    return 1;
                }
                $collection = $this->mongoService->getCollection($collectionName);
                $inserted = 0;
                $errors = 0;
                $batchSize = 500;
                $total = count($docs);
                for ($i = 0; $i < $total; $i += $batchSize) {
                    $batch = array_slice($docs, $i, $batchSize);
                    try {
                        $result = $collection->insertMany($batch, ['ordered' => false]);
                        $inserted += $result->getInsertedCount();
                    } catch (\MongoDB\Driver\Exception\BulkWriteException $e) {
                        $writeResult = $e->getWriteResult();
                        $inserted += $writeResult->getInsertedCount();
                        $writeErrors = $writeResult->getWriteErrors();
                        $errors += count($writeErrors);
                        foreach ($writeErrors as $we) {
                            $this->error('Bulk insert error: ' . $we->getMessage());
                        }
                    } catch (\Throwable $e) {
                        $errors += count($batch);
                        $this->error('MongoDB insertMany failed: ' . $e->getMessage());
                    }
                }
                $this->info("Attempted $total, inserted $inserted, errors $errors into MongoDB ($collectionName)");
            } elseif ($oneByOne) {
                foreach ($docs as $doc) {
                    $this->line(json_encode($doc));
                }
                $this->info("Exported " . count($docs) . " documents (one per line).");
            } else {
                $json = json_encode($docs, JSON_PRETTY_PRINT) . "\n";
                if ($output) {
                    file_put_contents($output, $json);
                    $this->info("Exported " . count($docs) . " documents to $output.");
                } else {
                    $this->line($json);
                    $this->info("Exported " . count($docs) . " documents to stdout.");
                }
            }
        } elseif ($direction === 'mongo-to-firestore') {
            if (!$this->mongoService) {
                $this->error('MongoDB service is not configured. Cannot migrate from MongoDB.');
                return 1;
            }
            $collection = $this->mongoService->getCollection($collectionName);
            $docs = $collection->find()->toArray();
            $docs = array_map(function ($doc) {
                $arr = (array) $doc;
                if (isset($arr['_id'])) {
                    $arr['id'] = (string) $arr['_id'];
                    unset($arr['_id']);
                }

                return $arr;
            }, $docs);
            $inserted = 0;
            $errors = 0;
            foreach ($docs as $doc) {
                try {
                    $this->documentStoreService->createDocument($collectionName, $doc);
                    $inserted++;
                } catch (\Throwable $e) {
                    $errors++;
                    $this->error("Firestore insert error for id={$doc['id']}: " . $e->getMessage());
                }
            }
            $this->info("Inserted $inserted of " . count($docs) . " docs into Firestore ($collectionName). Errors: $errors");
        } else {
            $this->error('Unknown direction: ' . $direction);
            return 1;
        }
        return 0;
    }
}
