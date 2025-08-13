<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonImmutable;

class DateNormalizer
{
    /**
     * Normalize an incoming date to Y-m-d or return null if not parseable.
     */
    public static function normalize(null|string|\DateTimeInterface $value): ?string
    {
        $date = self::parse($value);
        return $date ? $date->format('Y-m-d') : null;
    }

    /**
     * Parse many common date formats into a CarbonImmutable date (no time).
     */
    public static function parse(null|string|\DateTimeInterface $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance(Carbon::parse($value)->startOfDay());
        }

        $str = trim((string) $value);

        // Direct ISO date
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $str)) {
            return CarbonImmutable::createFromFormat('Y-m-d', $str)->startOfDay();
        }

        // ISO datetime variants
        try {
            if (preg_match('/^\d{4}-\d{2}-\d{2}T/', $str)) {
                return CarbonImmutable::parse($str)->startOfDay();
            }
        } catch (\Throwable $e) {
            // fall through
        }

        // Slash separated: prefer m/d/Y when ambiguous; fallback to d/m/Y when first part > 12
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $str, $m)) {
            $m1 = (int) $m[1];
            $m2 = (int) $m[2];
            $fmt = $m1 > 12 ? 'd/m/Y' : 'm/d/Y';
            $dt = CarbonImmutable::createFromFormat($fmt, $str);
            if ($dt instanceof CarbonImmutable) {
                return $dt->startOfDay();
            }
        }

        // Dash separated d-m-Y common in some locales
        if (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{4})$/', $str)) {
            $dt = CarbonImmutable::createFromFormat('d-m-Y', $str);
            if ($dt instanceof CarbonImmutable) {
                return $dt->startOfDay();
            }
        }

        // Fallback to Carbon parser
        try {
            return CarbonImmutable::parse($str)->startOfDay();
        } catch (\Throwable $e) {
            return null;
        }
    }
}
