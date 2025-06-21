<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class MigrateSeriesFormat extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'books:migrate-series-format';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Normalize the series field for all books in both MongoDB and Firestore to the canonical format.';

    public function handle()
    {
        $this->info('Starting MongoDB migration...');
        $this->updateMongoDB();
        $this->info('Starting Firestore migration...');
        $this->updateFirestore();
        $this->info('Migration complete.');
    }

    protected function canonicalizeSeries($series, $seriesName = null, $seriesNumber = null)
    {
        if (is_array($series) && isset($series[0]['seriesName'])) {
            return $series;
        }
        if (is_array($series) && isset($series[0]) && is_array($series[0]) && count($series[0]) === 1) {
            $out = [];
            foreach ($series as $item) {
                foreach ($item as $name => $number) {
                    $out[] = ['seriesName' => $name, 'number' => (string)$number];
                }
            }
            return $out;
        }
        if (is_array($series) && count(array_filter(array_keys($series), 'is_string'))) {
            $out = [];
            foreach ($series as $name => $number) {
                $out[] = ['seriesName' => $name, 'number' => (string)$number];
            }
            return $out;
        }
        if (is_string($series) && $series !== '') {
            return [['seriesName' => $series, 'number' => $seriesNumber ?? '']];
        }
        if ($seriesName && $seriesNumber) {
            return [['seriesName' => $seriesName, 'number' => $seriesNumber]];
        }
        if ($seriesName) {
            return [['seriesName' => $seriesName, 'number' => $seriesNumber ?? '']];
        }
        return [];
    }

    protected function updateMongoDB()
    {
        $mongoService = app(\App\Services\MongoService::class);
        $books = $mongoService->listBooks(); // Get all books
        $count = 0;
        foreach ($books as $doc) {
            $id = $doc['_id'] ?? $doc['id'] ?? null;
            $series = $doc['series'] ?? null;
            $seriesName = $doc['seriesName'] ?? null;
            $seriesNumber = $doc['seriesNumber'] ?? null;
            $canonical = $this->canonicalizeSeries($series, $seriesName, $seriesNumber);
            if ($series !== $canonical || (empty($series) && ($seriesName || $seriesNumber))) {
                $update = ['series' => $canonical];
                // Remove legacy fields
                unset($doc['seriesName'], $doc['seriesNumber']);
                $mongoService->updateBook($id, $update);
                $count++;
                $this->line("[MongoDB] Updated book {$id}");
            }
        }
        $this->info("[MongoDB] Migration complete. Updated {$count} records.");
    }

    protected function updateFirestore()
    {
        $firestoreService = app(\App\Services\FirestoreService::class);
        $books = $firestoreService->listBooks(); // Get all books
        $count = 0;
        foreach ($books as $doc) {
            $id = $doc['id'] ?? $doc['documentId'] ?? null;
            $series = $doc['series'] ?? null;
            $seriesName = $doc['seriesName'] ?? null;
            $seriesNumber = $doc['seriesNumber'] ?? null;
            $canonical = $this->canonicalizeSeries($series, $seriesName, $seriesNumber);
            if ($series !== $canonical || (empty($series) && ($seriesName || $seriesNumber))) {
                $update = ['series' => $canonical];
                unset($doc['seriesName'], $doc['seriesNumber']);
                $firestoreService->updateBook($id, $update);
                $count++;
                $this->line("[Firestore] Updated book {$id}");
            }
        }
        $this->info("[Firestore] Migration complete. Updated {$count} records.");
    }
}
