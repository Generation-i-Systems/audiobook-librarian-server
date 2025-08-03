<?php

namespace App\Console\Commands;

use App\Contracts\DocumentStoreServiceInterface;
use App\Services\FirestoreService;
use App\Services\MongoService;
use App\Services\MySqlService;
use Illuminate\Console\Command;
use MongoDB\Client;

class BenchmarkDatabasePerformance extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:benchmark-database-performance {driver=all : The driver to benchmark (mysql, mongo, all)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run a series of queries against database services to benchmark their performance.';

    private array $results = [];

    private ?DocumentStoreServiceInterface $mongoService = null;

    private ?DocumentStoreServiceInterface $mySqlService = null;

    private ?DocumentStoreServiceInterface $firestoreService = null;

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $driver = $this->argument('driver');

        if ($driver === 'all' || $driver === 'mongo') {
            // $this->mongoService = new MongoService(new Client(config('database.connections.mongodb.dsn')));
        }
        if ($driver === 'all' || $driver === 'mysql') {
            $this->mySqlService = new MySqlService();
        }
        if ($driver === 'all' || $driver === 'firestore') {
            $this->firestoreService = new FirestoreService();
        }

        $this->info('Starting database performance benchmark...');

        // Run benchmarks
        $this->runBenchmark('Get First Book', fn ($service) => $service->getBook(1));
        $this->runBenchmark('List All Books (50 limit)', fn ($service) => $service->listBooks()); // Assuming listBooks can be limited or just for comparison
        $this->runBenchmark('Dump All Books', fn ($service) => $service->dumpAllBooks());
        $this->runBenchmark('Autocomplete Author "King"', fn ($service) => $service->autocompleteAuthors('King'));
        $this->runBenchmark('Autocomplete Narrator "Scott"', fn ($service) => $service->autocompleteNarrators('Scott'));
        $this->runBenchmark('Autocomplete Series "Harry Potter"', fn ($service) => $service->autocompleteSeries('Harry Potter'));
        $this->runBenchmark('Get Books in Series (ID: 1)', fn ($service) => $service->getBooksInSeries(1));

        $this->displayResults();

        return 0;
    }

    private function runBenchmark(string $name, callable $callback)
    {
        $this->line("\nRunning benchmark: <fg=yellow>$name</fg=yellow>");

        if ($this->mySqlService) {
            $this->results['MySQL'][$name] = $this->measure($this->mySqlService, $callback);
            $this->info('MySQL... Done.');
        }
        if ($this->mongoService) {
            $this->results['MongoDB'][$name] = $this->measure($this->mongoService, $callback);
            $this->info('MongoDB... Done.');
        }
        if ($this->firestoreService) {
            $this->results['Firestore'][$name] = $this->measure($this->firestoreService, $callback);
            $this->info('Firestore... Done.');
        }
    }

    private function measure(DocumentStoreServiceInterface $service, callable $callback): array
    {
        $startTime = microtime(true);
        $startMemory = memory_get_peak_usage(true);

        $callback($service);

        $endTime = microtime(true);
        $endMemory = memory_get_peak_usage(true);

        return [
            'time' => ($endTime - $startTime) * 1000, // in ms
            'memory' => ($endMemory - $startMemory) / 1024 / 1024, // in MB
        ];
    }

    private function displayResults()
    {
        $this->line("\n<fg=green>Benchmark Results:</fg=green>");

        $headers = ['Benchmark', 'Driver', 'Time (ms)', 'Memory (MB)'];
        $rows = [];

        foreach ($this->results as $driver => $benchmarks) {
            foreach ($benchmarks as $name => $metrics) {
                $time = number_format($metrics['time'], 2);
                $memory = number_format($metrics['memory'], 2);
                $rows[] = [$name, $driver, $time, $memory];
            }
        }

        $this->table($headers, $rows);
    }
}
