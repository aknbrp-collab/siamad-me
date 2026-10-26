<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Gap-free running numbers (bills, handovers, receipts) per prefix and year,
 * e.g. "KW/2026/000123". Must run inside the caller's transaction: the row
 * lock serialises concurrent requests and a rollback releases the number.
 */
final class NumberSequence
{
    public static function next(string $prefix, ?int $year = null): string
    {
        $year ??= (int) now()->format('Y');
        $key = "{$prefix}/{$year}";

        DB::table('number_sequences')->insertOrIgnore(['key' => $key, 'last_value' => 0]);

        $current = DB::table('number_sequences')->where('key', $key)->lockForUpdate()->value('last_value');
        $next = (int) $current + 1;

        DB::table('number_sequences')->where('key', $key)->update(['last_value' => $next]);

        return sprintf('%s/%d/%06d', $prefix, $year, $next);
    }
}
