<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Book;
use App\Models\Series;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixSeriesStartingWithNumberCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'series:fix-number-prefix
                            {--dry-run : Run without making changes}
                            {--interactive : Prompt for confirmation on each series}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fix series entries that start with a number by reparsing directory_path';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $dryRun = $this->option('dry-run');
        $interactive = $this->option('interactive');

        if ($dryRun) {
            $this->warn('DRY RUN MODE: No changes will be made');
        }

        // Find all series that start with a number
        $this->info('Finding series that start with a number...');
        $seriesWithNumberPrefix = Series::whereRaw('name REGEXP \'^[0-9]\'')->get();

        if ($seriesWithNumberPrefix->isEmpty()) {
            $this->info('No series found that start with a number.');

            return 0;
        }

        $this->info(sprintf('Found %d series that start with a number', $seriesWithNumberPrefix->count()));

        $fixed = 0;
        $errors = 0;
        $skipped = 0;
        $userSkipped = 0;

        foreach ($seriesWithNumberPrefix as $series) {
            $this->info(sprintf('Processing series: %s (ID: %s)', $series->name, $series->id));

            // Get all books in this series
            $books = $series->books;

            if ($books->isEmpty()) {
                $this->warn('No books found in this series, skipping');
                $skipped++;
                continue;
            }

            $this->info(sprintf('Found %d books in this series', $books->count()));

            // Try to determine the correct series name by reparsing directory_path
            $potentialSeriesNames = [];

            foreach ($books as $book) {
                if (empty($book->directory_path)) {
                    continue;
                }

                $parsedPath = $this->parseDirectoryPath($book->directory_path);

                if (! empty($parsedPath['series'])) {
                    $potentialSeriesNames[] = $parsedPath['series'];
                }
            }

            // Count occurrences of each potential series name
            $seriesNameCounts = array_count_values($potentialSeriesNames);
            arsort($seriesNameCounts); // Sort by frequency, most frequent first

            // If we found potential series names
            /** @phpstan-ignore-next-line */
            if (count($seriesNameCounts) > 0) {
                $mostFrequentSeriesName = array_key_first($seriesNameCounts);
                $frequency = $seriesNameCounts[$mostFrequentSeriesName];
                $confidence = $frequency / $books->count();

                $this->info(sprintf(
                    'Most frequent series name from directory paths: %s (found in %d/%d books, confidence: %.2f%%)',
                    $mostFrequentSeriesName,
                    $frequency,
                    $books->count(),
                    $confidence * 100
                ));

                // If the reparsed name is the same as the current name, it's likely incorrect
                if ($mostFrequentSeriesName === $series->name) {
                    $this->warn('Reparsed series name is the same as the current name, likely incorrect');

                    if ($interactive) {
                        if (
                            ! $this->confirmAction(sprintf(
                                'Please enter the correct series name for "%s" or press Enter to skip:',
                                $series->name
                            ), true)
                        ) {
                            $userSkipped++;
                            continue;
                        }

                        $newSeriesName = $this->ask('Enter the correct series name:');

                        if (empty($newSeriesName)) {
                            $this->warn('No name provided, skipping');
                            $userSkipped++;
                            continue;
                        }

                        $mostFrequentSeriesName = $newSeriesName;
                    } else {
                        $this->warn('Interactive mode disabled, skipping this series');
                        $skipped++;
                        continue;
                    }
                }

                // Check if a series with the new name already exists
                $existingSeries = Series::where('name', $mostFrequentSeriesName)->first();

                if ($existingSeries) {
                    $this->info(sprintf(
                        'Found existing series with name "%s" (ID: %s)',
                        $mostFrequentSeriesName,
                        $existingSeries->id
                    ));

                    // Ask for confirmation if in interactive mode
                    if ($interactive) {
                        if (
                            ! $this->confirmAction(sprintf(
                                'Move books from "%s" to existing series "%s"?',
                                $series->name,
                                $mostFrequentSeriesName
                            ))
                        ) {
                            $userSkipped++;
                            continue;
                        }
                    }

                    if (! $dryRun) {
                        try {
                            // Begin transaction
                            DB::beginTransaction();

                            // For each book in the old series, move it to the new series
                            foreach ($books as $book) {
                                // Get the series number if it exists
                                $pivotData = $book->series()->where('series.id', $series->id)->first()->pivot;
                                $seriesNumber = $pivotData->series_number ?? null;

                                // Detach from old series
                                $book->series()->detach($series->id);

                                // Attach to new series with the same series number
                                $book->series()->attach($existingSeries->id, [
                                    'series_number' => $seriesNumber,
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ]);

                                $this->info(sprintf(
                                    'Moved book "%s" (ID: %s) to series "%s"',
                                    $book->title,
                                    $book->id,
                                    $existingSeries->name
                                ));
                            }

                            // Delete the old series if it no longer has any books
                            if ($series->books()->count() === 0) {
                                $series->delete();
                                $this->info(sprintf('Deleted empty series "%s" (ID: %s)', $series->name, $series->id));
                            }

                            DB::commit();
                            $fixed++;
                        } catch (\Exception $e) {
                            DB::rollBack();
                            $this->error('Error moving books to existing series: ' . $e->getMessage());
                            $errors++;
                        }
                    } else {
                        $this->info('[DRY RUN] Would move books to existing series and delete old series');
                        $fixed++;
                    }
                } else {
                    // No existing series with this name, rename the current series
                    $this->info(sprintf(
                        'No existing series found with name "%s", will rename current series',
                        $mostFrequentSeriesName
                    ));

                    // Ask for confirmation if in interactive mode
                    if ($interactive) {
                        if (
                            ! $this->confirmAction(sprintf(
                                'Rename series from "%s" to "%s"?',
                                $series->name,
                                $mostFrequentSeriesName
                            ))
                        ) {
                            $userSkipped++;
                            continue;
                        }
                    }

                    if (! $dryRun) {
                        try {
                            $series->name = $mostFrequentSeriesName;
                            $series->save();
                            $this->info(sprintf(
                                'Renamed series from "%s" to "%s" (ID: %s)',
                                $series->getOriginal('name'),
                                $mostFrequentSeriesName,
                                $series->id
                            ));
                            $fixed++;
                        } catch (\Exception $e) {
                            $this->error('Error renaming series: ' . $e->getMessage());
                            $errors++;
                        }
                    } else {
                        $this->info('[DRY RUN] Would rename series');
                        $fixed++;
                    }
                }
            } else {
                $this->warn('Could not determine a better series name from directory paths');

                if ($interactive) {
                    if (
                        ! $this->confirmAction(sprintf(
                            'Please enter the correct series name for "%s" or press Enter to skip:',
                            $series->name
                        ), true)
                    ) {
                        $userSkipped++;
                        continue;
                    }

                    $newSeriesName = $this->ask('Enter the correct series name:');

                    if (empty($newSeriesName)) {
                        $this->warn('No name provided, skipping');
                        $userSkipped++;
                        continue;
                    }

                    // Check if a series with the new name already exists
                    $existingSeries = Series::where('name', $newSeriesName)->first();

                    if ($existingSeries) {
                        if (! $dryRun) {
                            try {
                                // Begin transaction
                                DB::beginTransaction();

                                // For each book in the old series, move it to the new series
                                foreach ($books as $book) {
                                    // Get the series number if it exists
                                    $pivotData = $book->series()->where('series.id', $series->id)->first()->pivot;
                                    $seriesNumber = $pivotData->series_number ?? null;

                                    // Detach from old series
                                    $book->series()->detach($series->id);

                                    // Attach to new series with the same series number
                                    $book->series()->attach($existingSeries->id, [
                                        'series_number' => $seriesNumber,
                                        'created_at' => now(),
                                        'updated_at' => now(),
                                    ]);

                                    $this->info(sprintf(
                                        'Moved book "%s" (ID: %s) to series "%s"',
                                        $book->title,
                                        $book->id,
                                        $existingSeries->name
                                    ));
                                }

                                // Delete the old series if it no longer has any books
                                if ($series->books()->count() === 0) {
                                    $series->delete();
                                    $this->info(sprintf('Deleted empty series "%s" (ID: %s)', $series->name, $series->id));
                                }

                                DB::commit();
                                $fixed++;
                            } catch (\Exception $e) {
                                DB::rollBack();
                                $this->error('Error moving books to existing series: ' . $e->getMessage());
                                $errors++;
                            }
                        } else {
                            $this->info('[DRY RUN] Would move books to existing series and delete old series');
                            $fixed++;
                        }
                    } else {
                        // No existing series with this name, rename the current series
                        if (! $dryRun) {
                            try {
                                $series->name = $newSeriesName;
                                $series->save();
                                $this->info(sprintf(
                                    'Renamed series from "%s" to "%s" (ID: %s)',
                                    $series->getOriginal('name'),
                                    $newSeriesName,
                                    $series->id
                                ));
                                $fixed++;
                            } catch (\Exception $e) {
                                $this->error('Error renaming series: ' . $e->getMessage());
                                $errors++;
                            }
                        } else {
                            $this->info('[DRY RUN] Would rename series');
                            $fixed++;
                        }
                    }
                } else {
                    $this->warn('Interactive mode disabled, skipping this series');
                    $skipped++;
                }
            }
        }

        $this->info('');
        $this->info('Summary:');
        $this->info(sprintf('Series fixed: %d', $fixed));
        $this->info(sprintf('Series skipped: %d', $skipped));
        $this->info(sprintf('Series skipped by user: %d', $userSkipped));
        $this->info(sprintf('Errors: %d', $errors));

        return 0;
    }

    /**
     * Parse a directory path into its components.
     *
     * @param string $dir
     *
     * @return array
     */
    private function parseDirectoryPath($dir)
    {
        $parts = explode('/', $dir);

        if (count($parts) >= 5) {
            return [
                'genre' => $parts[0],
                'author' => $parts[1],
                'series' => $parts[2],
                'number' => $parts[3],
                'title' => $parts[4],
            ];
        }

        if (count($parts) >= 3) {
            return [
                'genre' => $parts[0],
                'author' => $parts[1],
                'title' => $parts[count($parts) - 1],
            ];
        }

        return [];
    }

    /**
     * Ask for confirmation.
     *
     * @param string $question
     * @param bool $allowSkip
     *
     * @return bool
     */
    private function confirmAction($question, $allowSkip = false)
    {
        $options = $allowSkip ? ['y', 'n', ''] : ['y', 'n'];
        $default = $allowSkip ? '' : 'n';

        $answer = $this->choice($question, $options, $default);

        if ($answer === '') {
            return false;
        }

        return $answer === 'y';
    }
}
