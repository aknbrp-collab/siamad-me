<?php

namespace App\Support;

final class Money
{
    /**
     * Whole rupiah as "Rp 1.250.000" (negative: "-Rp 250.000").
     */
    public static function format(int $amount): string
    {
        return ($amount < 0 ? '-' : '').'Rp '.number_format(abs($amount), 0, ',', '.');
    }

    /**
     * Indonesian amount in words for receipts (terbilang),
     * e.g. 1250000 → "satu juta dua ratus lima puluh ribu rupiah".
     */
    public static function inWords(int $amount): string
    {
        return trim(preg_replace('/\s+/', ' ', self::words(abs($amount)) ?: 'nol')).' rupiah';
    }

    private static function words(int $n): string
    {
        $units = ['', 'satu', 'dua', 'tiga', 'empat', 'lima', 'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas'];

        return match (true) {
            $n < 12 => $units[$n],
            $n < 20 => self::words($n - 10).' belas',
            $n < 100 => self::words(intdiv($n, 10)).' puluh '.self::words($n % 10),
            $n < 200 => 'seratus '.self::words($n - 100),
            $n < 1000 => self::words(intdiv($n, 100)).' ratus '.self::words($n % 100),
            $n < 2000 => 'seribu '.self::words($n - 1000),
            $n < 1_000_000 => self::words(intdiv($n, 1000)).' ribu '.self::words($n % 1000),
            $n < 1_000_000_000 => self::words(intdiv($n, 1_000_000)).' juta '.self::words($n % 1_000_000),
            default => self::words(intdiv($n, 1_000_000_000)).' miliar '.self::words($n % 1_000_000_000),
        };
    }
}
