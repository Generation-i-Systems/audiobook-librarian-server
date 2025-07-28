<?php

namespace App\Providers;

use App\Contracts\DocumentStoreServiceInterface;
use App\Services\FirestoreService;
use App\Services\MongoService;
use App\Services\MySqlService;
use Illuminate\Support\ServiceProvider;
use Tests\Mocks\MockDocumentStoreService;

class DocumentStoreServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(DocumentStoreServiceInterface::class, function ($app) {
            if ($app->environment('testing')) {
                return new MockDocumentStoreService();
            }

            $driver = config('documentstore.driver', 'mysql');
            
            // Debug: Always log what driver is being requested and by whom
            $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);
            $caller = '';
            foreach ($backtrace as $trace) {
                if (isset($trace['class']) && !str_contains($trace['class'], 'ServiceProvider')) {
                    $caller = ($trace['class'] ?? 'Unknown') . '::' . ($trace['function'] ?? 'unknown');
                    break;
                }
            }
            
            \Log::info("DocumentStoreService requested: driver='{$driver}' by {$caller}");
            
            // Debug: Report when MongoDB is being used outside of migration commands
            if ($driver === 'mongodb') {
                
                // Check if this is an acceptable MongoDB usage (migration/mongo commands)
                $isAcceptableMongoUsage = false;
                $allowedMongoCommands = [
                    'MigrateMongoToMysql',
                    'CompareMongoMysqlBooks', 
                    'MongoTestCommand',
                    'MigrateSeriesFormat'
                ];
                
                foreach ($backtrace as $trace) {
                    if (isset($trace['class'])) {
                        foreach ($allowedMongoCommands as $allowedCommand) {
                            if (str_contains($trace['class'], $allowedCommand)) {
                                $isAcceptableMongoUsage = true;
                                break 2;
                            }
                        }
                    }
                }
                
                if (!$isAcceptableMongoUsage) {
                    \Log::error("MongoDB being used inappropriately by: {$caller}");
                    \Log::error("Stack trace: " . json_encode(array_slice($backtrace, 0, 5)));
                }
            }

            return match ($driver) {
                'mongodb' => new MongoService(),
                'firestore' => new FirestoreService(),
                default => new MySqlService(),
            };
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
