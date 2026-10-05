<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Audit;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Moves old audit rows out of the database: export a batch to a verified
 * gzip NDJSON file, and only then delete exactly the rows that were exported.
 * Any failure before the delete leaves the database untouched.
 */
class AuditArchiveService
{
    public function __construct(
        private readonly string $archivePath,
        private readonly int $chunkSize = 5000,
    ) {
    }

    public function pendingCount(CarbonInterface $cutoff): int
    {
        return Audit::query()->where('created_at', '<', $cutoff)->count();
    }

    /**
     * @return array{batches: int, rows: int, files: array<int, string>}
     */
    public function archive(CarbonInterface $cutoff, ?callable $progress = null): array
    {
        if (!is_dir($this->archivePath) && !mkdir($this->archivePath, 0755, true) && !is_dir($this->archivePath)) {
            throw new RuntimeException("Cannot create audit archive directory: {$this->archivePath}");
        }
        if (!is_writable($this->archivePath)) {
            throw new RuntimeException("Audit archive directory is not writable: {$this->archivePath}");
        }

        $summary = ['batches' => 0, 'rows' => 0, 'files' => []];

        while (true) {
            $rows = Audit::query()->toBase()
                ->where('created_at', '<', $cutoff)
                ->orderBy('id')
                ->limit(max(1, $this->chunkSize))
                ->get();
            if ($rows->isEmpty()) {
                break;
            }

            $ids = $rows->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $file = $this->writeArchiveFile($rows->map(fn ($row): array => (array) $row)->all(), $ids);

            // Deletion happens only after the file has been re-read and matched.
            $deleted = DB::transaction(fn (): int => Audit::query()->whereIn('id', $ids)->delete());
            if ($deleted !== count($ids)) {
                throw new RuntimeException("Archived {$file} but deleted {$deleted} of " . count($ids) . ' rows.');
            }

            $summary['batches']++;
            $summary['rows'] += count($ids);
            $summary['files'][] = $file;
            if ($progress !== null) {
                $progress($file, count($ids));
            }
        }

        return $summary;
    }

    /**
     * Load an archive file back into the audits table. Rows whose id already exists are left alone.
     */
    public function restore(string $file): int
    {
        $handle = gzopen($file, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Cannot open audit archive: {$file}");
        }

        $inserted = 0;
        $batch = [];
        try {
            while (($line = gzgets($handle)) !== false) {
                $line = rtrim($line, "\n");
                if ($line === '') {
                    continue;
                }
                $batch[] = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                if (count($batch) >= 1000) {
                    $inserted += Audit::query()->insertOrIgnore($batch);
                    $batch = [];
                }
            }
            if ($batch !== []) {
                $inserted += Audit::query()->insertOrIgnore($batch);
            }
        } finally {
            gzclose($handle);
        }

        return $inserted;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, int> $ids
     */
    private function writeArchiveFile(array $rows, array $ids): string
    {
        $final = rtrim($this->archivePath, '/') . '/audits_' . min($ids) . '_' . max($ids) . '.ndjson.gz';
        $partial = $final . '.part';

        $handle = gzopen($partial, 'wb6');
        if ($handle === false) {
            throw new RuntimeException("Cannot write audit archive: {$partial}");
        }
        $hash = hash_init('sha256');
        try {
            foreach ($rows as $row) {
                $line = json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
                hash_update($hash, $line);
                if (gzwrite($handle, $line) === false) {
                    throw new RuntimeException("Cannot write audit archive: {$partial}");
                }
            }
        } catch (\Throwable $e) {
            gzclose($handle);
            @unlink($partial);
            throw $e;
        }
        gzclose($handle);

        try {
            $this->verify($partial, $ids, hash_final($hash));
        } catch (\Throwable $e) {
            @unlink($partial);
            throw $e;
        }

        if (!@rename($partial, $final)) {
            @unlink($partial);
            throw new RuntimeException("Cannot publish audit archive: {$final}");
        }
        file_put_contents($final . '.sha256', hash_file('sha256', $final) . '  ' . basename($final) . "\n");

        return $final;
    }

    /**
     * @param array<int, int> $expectedIds
     */
    private function verify(string $file, array $expectedIds, string $expectedHash): void
    {
        $handle = gzopen($file, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Cannot re-read audit archive for verification: {$file}");
        }
        $hash = hash_init('sha256');
        $ids = [];
        try {
            while (($line = gzgets($handle)) !== false) {
                hash_update($hash, $line);
                $decoded = json_decode(rtrim($line, "\n"), true, 512, JSON_THROW_ON_ERROR);
                $ids[] = (int) $decoded['id'];
            }
        } finally {
            gzclose($handle);
        }

        if ($ids !== $expectedIds || !hash_equals($expectedHash, hash_final($hash))) {
            throw new RuntimeException("Audit archive failed verification, nothing was deleted: {$file}");
        }
    }
}
