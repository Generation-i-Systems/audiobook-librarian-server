<?php

declare(strict_types=1);

namespace App\Console\Commands\LibriVox;

use App\Models\LibriVoxSyncLog;
use App\Services\LibriVoxApiService;
use App\Services\LibriVoxImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncLibriVoxCommand extends Command
{
    protected $signature = 'librivox:sync
        {--language=English : Language to sync (e.g. English, German)}
        {--full : Force a full sync even if a previous sync exists}
        {--dry-run : Fetch and count without importing}';

    protected $description = 'Sync LibriVox catalog to local database (supports incremental delta syncs)';

    private bool $cancelled = false;
    private float $startTime = 0.0;

    public function __construct(
        private readonly LibriVoxApiService $api,
        private readonly LibriVoxImportService $importService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        config(['logging.default' => 'librivox']);

        $language  = (string) $this->option('language');
        $forceFull = (bool) $this->option('full');
        $dryRun    = (bool) $this->option('dry-run');
        $verbose   = $this->getOutput()->isVerbose();

        // Register signal handlers so Ctrl+C or SIGTERM cancels cleanly
        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);
            $handler = function () use ($language): void {
                $this->cancelled = true;
                $this->warn('Cancellation signal received — finishing current page...');
                Log::warning('LibriVox sync cancelled via signal', ['language' => $language]);
            };
            pcntl_signal(SIGTERM, $handler);
            pcntl_signal(SIGINT, $handler);
        }

        // Mark stale running records as failed
        LibriVoxSyncLog::where('language', $language)
            ->where('status', 'running')
            ->where('started_at', '<', now()->subHours(2))
            ->update(['status' => 'failed', 'error_message' => 'Timed out / abandoned']);

        $running = LibriVoxSyncLog::where('language', $language)
            ->where('status', 'running')
            ->exists();

        if ($running && !$forceFull && !$dryRun) {
            $this->warn("A sync for \"{$language}\" is already running. Use --full to force a new one.");
            return self::SUCCESS;
        }

        if ($running && $forceFull) {
            LibriVoxSyncLog::where('language', $language)
                ->where('status', 'running')
                ->update(['status' => 'failed', 'error_message' => 'Superseded by --full']);
        }

        $last     = LibriVoxSyncLog::lastCompleted($language);
        $isDelta  = $last !== null && !$forceFull;
        $since    = $isDelta ? $last->started_at->timestamp : null;
        $syncType = $isDelta ? 'delta' : 'full';

        $this->info(sprintf(
            'Starting %s sync for language "%s"%s',
            $syncType,
            $language,
            $isDelta ? ' (since ' . $last->started_at->toDateTimeString() . ')' : '',
        ));

        $log = $dryRun ? null : LibriVoxSyncLog::create([
            'language'        => $language,
            'sync_type'       => $syncType,
            'started_at'      => now(),
            'since_timestamp' => $since,
            'status'          => 'running',
            'pid'             => getmypid(),
        ]);

        $imported = $failed = $pages = 0;
        $pageSize = 50;
        $offset   = 0;
        $this->startTime = microtime(true);

        try {
            do {
                // Check DB cancellation flag (for web-triggered cancels)
                if ($log && $this->isCancelledInDb($log->id)) {
                    $this->cancelled = true;
                }

                if ($this->cancelled) {
                    break;
                }

                if ($verbose) {
                    $this->line(sprintf(
                        '<fg=gray>  Fetching page %d (offset %d)...</>',
                        $pages + 1,
                        $offset,
                    ));
                }

                $response = $this->fetchPageWithRetry($pageSize, $offset, $since, $language);

                if ($response === null) {
                    // null only returned when cancelled during backoff sleep
                    break;
                }

                $books = $response['books'];

                if (empty($books)) {
                    break;
                }

                $pageImported = $pageFailed = 0;

                foreach ($books as $apiBook) {
                    if ($dryRun) {
                        $imported++;
                        $pageImported++;
                        if ($verbose) {
                            $this->line(sprintf(
                                '  <fg=gray>[dry-run]</> %s',
                                $apiBook['title'] ?? '(untitled)',
                            ));
                        }
                        continue;
                    }

                    try {
                        $this->importService->importBook($apiBook);
                        $imported++;
                        $pageImported++;
                        if ($verbose) {
                            $authors = collect($apiBook['authors'] ?? [])
                                ->map(fn ($a) => trim(($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? '')))
                                ->filter()
                                ->join(', ');
                            $this->line(sprintf(
                                '  <fg=green>✓</> %s%s',
                                $apiBook['title'] ?? '(untitled)',
                                $authors ? " <fg=gray>— {$authors}</>" : '',
                            ));
                        }
                    } catch (\Exception $e) {
                        $failed++;
                        $pageFailed++;
                        Log::error('LibriVox sync import error', [
                            'id'    => $apiBook['id'] ?? '?',
                            'error' => $e->getMessage(),
                        ]);
                        if ($verbose) {
                            $this->line(sprintf(
                                '  <fg=red>✗</> %s <fg=gray>(%s)</>',
                                $apiBook['title'] ?? '(untitled)',
                                $e->getMessage(),
                            ));
                        }
                    }
                }

                $pages++;
                $offset += $pageSize;

                $elapsed = microtime(true) - $this->startTime;
                $rate    = $elapsed > 0 ? round($imported / $elapsed * 60) : 0;

                if ($verbose) {
                    $this->line(sprintf(
                        '  <fg=cyan>Page %d done:</> +%d imported%s | total %d | %s elapsed | %d books/min',
                        $pages,
                        $pageImported,
                        $pageFailed > 0 ? ", {$pageFailed} failed" : '',
                        $imported,
                        $this->formatElapsed($elapsed),
                        $rate,
                    ));
                } else {
                    $this->line(sprintf(
                        '  Page %d | %s imported%s | %s elapsed | %d/min',
                        $pages,
                        number_format($imported),
                        $failed > 0 ? ", {$failed} failed" : '',
                        $this->formatElapsed($elapsed),
                        $rate,
                    ));
                }

                if ($log) {
                    $log->update(['books_imported' => $imported, 'books_failed' => $failed]);
                }
            } while (count($books) === $pageSize);

            $sizesUpdated = 0;

            if (!$dryRun && !$this->cancelled && $imported > 0) {
                $this->line('  Fetching chapter sizes via HTTP HEAD...');
                $lastReport   = 0;
                $sizesUpdated = $this->importService->backfillChapterSizes(
                    null,
                    function (int $processed, int $updated) use (&$lastReport): void {
                        if ($processed - $lastReport >= 500) {
                            $this->line(sprintf(
                                '    %s processed, %s sizes fetched',
                                number_format($processed),
                                number_format($updated),
                            ));
                            $lastReport = $processed;
                        }
                    },
                );
                $this->line(sprintf('  Chapter sizes updated: %s', number_format($sizesUpdated)));
            }

            $finalStatus = $this->cancelled ? 'cancelled' : 'completed';
            $elapsed     = microtime(true) - $this->startTime;

            if ($log) {
                $log->update([
                    'status'         => $finalStatus,
                    'completed_at'   => now(),
                    'books_imported' => $imported,
                    'books_failed'   => $failed,
                    'pid'            => null,
                ]);
            }

            $this->info(sprintf(
                '%s %s: %s imported%s in %d pages, %s chapter sizes fetched (%s)',
                $dryRun ? 'Dry-run' : ucfirst($syncType),
                $finalStatus,
                number_format($imported),
                $failed > 0 ? ", {$failed} failed" : '',
                $pages,
                number_format($sizesUpdated),
                $this->formatElapsed($elapsed),
            ));

            return self::SUCCESS;
        } catch (\Exception $e) {
            if ($log) {
                $log->update([
                    'status'         => 'failed',
                    'completed_at'   => now(),
                    'error_message'  => $e->getMessage(),
                    'books_imported' => $imported,
                    'books_failed'   => $failed,
                    'pid'            => null,
                ]);
            }

            $this->error('Sync failed: ' . $e->getMessage());
            Log::error('LibriVox sync failed', ['error' => $e->getMessage()]);

            return self::FAILURE;
        }
    }

    private function formatElapsed(float $seconds): string
    {
        if ($seconds < 60) {
            return round($seconds) . 's';
        }
        if ($seconds < 3600) {
            return sprintf('%dm%02ds', (int) ($seconds / 60), (int) ($seconds % 60));
        }
        return sprintf('%dh%02dm', (int) ($seconds / 3600), (int) (($seconds % 3600) / 60));
    }

    /**
     * Fetch one page from the API with exponential backoff on failure.
     * Backoff starts at 30s, doubles each attempt, caps at 3600s (1 hour).
     * Retries indefinitely at 1-hour intervals once the cap is reached.
     * Aborts only if cancelled.
     *
     * @return array{books: array<int, array<string, mixed>>}|null  null means cancelled
     */
    private function fetchPageWithRetry(int $limit, int $offset, ?int $since, string $language): ?array
    {
        $attempt    = 0;
        $maxBackoff = 3600;

        while (true) {
            $response = $this->api->listBooks($limit, $offset, $since, $language);

            if ($response !== null) {
                return $response;
            }

            $wait = min(30 * (2 ** $attempt), $maxBackoff);

            $this->warn(sprintf(
                'API request failed at offset %d (attempt %d) — retrying in %s...',
                $offset,
                $attempt + 1,
                $wait >= 60 ? round($wait / 60, 1) . 'm' : "{$wait}s",
            ));
            Log::warning('LibriVox API retry', [
                'offset'  => $offset,
                'attempt' => $attempt + 1,
                'wait_s'  => $wait,
            ]);

            if (!$this->backoffSleep($wait)) {
                return null; // cancelled during sleep
            }

            if ($wait < $maxBackoff) {
                $attempt++;
            }
        }
    }

    /**
     * Sleep for $seconds, checking for cancellation every 5 seconds.
     * Returns false if cancelled, true if the full wait completed.
     */
    private function backoffSleep(int $seconds): bool
    {
        $elapsed = 0;
        while ($elapsed < $seconds) {
            if ($this->cancelled) {
                return false;
            }
            $chunk = min(5, $seconds - $elapsed);
            sleep($chunk);
            $elapsed += $chunk;
        }

        return !$this->cancelled;
    }

    private function isCancelledInDb(int $logId): bool
    {
        return LibriVoxSyncLog::where('id', $logId)
            ->where('status', 'cancelling')
            ->exists();
    }
}
