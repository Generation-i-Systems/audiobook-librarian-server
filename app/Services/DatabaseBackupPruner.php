<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;

class DatabaseBackupPruner
{
    /** @return array{keep: list<string>, delete: list<string>} */
    public function plan(string $directory, string $identifier, DateTimeImmutable $now): array
    {
        $prefix = 'backup_' . $identifier . '_';
        $files = array_merge(
            glob($directory . '/' . $prefix . '*.sql.gz') ?: [],
            glob($directory . '/' . $prefix . '*.sqlite') ?: [],
        );
        $candidates = [];

        foreach ($files as $file) {
            if (!is_file($file) || is_link($file)) {
                continue;
            }

            $name = basename($file);
            if (!preg_match('/^' . preg_quote($prefix, '/') . '(?:.+_)?(\d{8}_\d{6})\.(?:sql\.gz|sqlite)$/D', $name, $matches)) {
                continue;
            }

            $date = DateTimeImmutable::createFromFormat('!Ymd_His', $matches[1], $now->getTimezone());
            if ($date === false || $date->format('Ymd_His') !== $matches[1] || $date > $now) {
                continue;
            }

            $candidates[] = ['file' => $file, 'date' => $date, 'stamp' => $matches[1]];
        }

        usort($candidates, static function (array $left, array $right): int {
            return strcmp($right['stamp'], $left['stamp']) ?: strcmp($right['file'], $left['file']);
        });

        $keep = [];
        $delete = [];
        $periods = [];
        $dayAgo = $now->modify('-1 day');
        $monthAgo = $now->modify('-1 month');

        foreach ($candidates as $candidate) {
            $file = $candidate['file'];
            $date = $candidate['date'];

            if ($date >= $dayAgo) {
                $period = 'day:recent';
            } elseif ($date >= $monthAgo) {
                $period = 'week:' . $date->format('o-W');
            } else {
                $period = 'month:' . $date->format('Y-m');
            }

            if (isset($periods[$period])) {
                $delete[] = $file;
            } else {
                $periods[$period] = true;
                $keep[] = $file;
            }
        }

        return ['keep' => $keep, 'delete' => $delete];
    }
}
