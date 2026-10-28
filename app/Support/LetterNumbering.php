<?php

namespace App\Support;

use App\Models\Setting;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Outgoing letter numbers (v3 D12): one counter shared by every letter type,
 * restarting at 1 each Gregorian year, never reused. The format is entered by
 * Superadmin, e.g. "{nomor}/MA/{kode}/{bulan-romawi}/{tahun}" → 001/MA/SKA/X/2026.
 * A starting number per year lets numbering continue from a paper register.
 */
final class LetterNumbering
{
    public const DEFAULT_FORMAT = '{nomor}/MA/{kode}/{bulan-romawi}/{tahun}';

    public const TOKENS = ['{nomor}', '{kode}', '{bulan-romawi}', '{tahun}'];

    public static function format(): string
    {
        $format = Setting::get('letters.number_format');

        return is_string($format) && $format !== '' ? $format : self::DEFAULT_FORMAT;
    }

    public static function padding(): int
    {
        return max(1, min(6, (int) (Setting::get('letters.padding') ?? 3)));
    }

    /**
     * The first number of a year (letters already numbered on paper come before it).
     */
    public static function startFor(int $year): int
    {
        return max(1, (int) (Setting::get('letters.start.'.$year) ?? 1));
    }

    /**
     * Check a format entered in the form: it needs {nomor} and only known parts.
     */
    public static function validate(string $format): void
    {
        if (! str_contains($format, '{nomor}')) {
            throw ValidationException::withMessages(['numberFormat' => __('The format needs {nomor}, the counter.')]);
        }

        preg_match_all('/\{[^}]*\}/', $format, $matches);
        $unknown = array_diff($matches[0], self::TOKENS);

        if ($unknown !== []) {
            throw ValidationException::withMessages(['numberFormat' => __('Unknown part :parts. Use :tokens.', [
                'parts' => implode(' ', $unknown),
                'tokens' => implode(' ', self::TOKENS),
            ])]);
        }
    }

    public static function render(int $sequence, string $code, CarbonInterface $date, ?string $format = null, ?int $padding = null): string
    {
        return strtr($format ?? self::format(), [
            '{nomor}' => str_pad((string) $sequence, $padding ?? self::padding(), '0', STR_PAD_LEFT),
            '{kode}' => $code,
            '{bulan-romawi}' => self::roman($date->month),
            '{tahun}' => (string) $date->year,
        ]);
    }

    /**
     * The next counter value of a year, without taking it (for the live example).
     */
    public static function peek(int $year): int
    {
        $last = (int) DB::table('number_sequences')->where('key', self::key($year))->value('last_value');

        return max($last + 1, self::startFor($year));
    }

    /**
     * Take the next number. Must run inside the caller's transaction: the row
     * lock makes two letters issued at the same moment get different numbers.
     *
     * @return array{number: string, sequence: int, year: int}
     */
    public static function take(string $code, CarbonInterface $date): array
    {
        $key = self::key($date->year);

        DB::table('number_sequences')->insertOrIgnore(['key' => $key, 'last_value' => 0]);
        $last = (int) DB::table('number_sequences')->where('key', $key)->lockForUpdate()->value('last_value');
        $sequence = max($last + 1, self::startFor($date->year));
        DB::table('number_sequences')->where('key', $key)->update(['last_value' => $sequence]);

        return ['number' => self::render($sequence, $code, $date), 'sequence' => $sequence, 'year' => $date->year];
    }

    public static function roman(int $month): string
    {
        return ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'][$month - 1];
    }

    private static function key(int $year): string
    {
        return 'SURAT/'.$year;
    }
}
