<?php

namespace App\Support;

use App\Models\Guardian;

/**
 * Generators for login credentials handed out on paper: readable characters
 * only (no 0/O, 1/I/l).
 */
final class Credentials
{
    private const UPPER = 'ABCDEFGHJKLMNPQRSTUVWXYZ';

    private const LOWER = 'abcdefghijkmnpqrstuvwxyz';

    private const DIGITS = '23456789';

    public static function temporaryPassword(int $length = 10): string
    {
        $alphabet = self::UPPER.self::LOWER.self::DIGITS;

        // Guarantee one of each class, then fill and shuffle.
        $chars = [self::pick(self::UPPER), self::pick(self::LOWER), self::pick(self::DIGITS)];

        while (count($chars) < $length) {
            $chars[] = self::pick($alphabet);
        }

        shuffle($chars);

        return implode('', $chars);
    }

    /**
     * A unique guardian login code such as "WS-7KQ2MPXA".
     */
    public static function guardianCode(): string
    {
        do {
            $code = 'WS-';

            for ($i = 0; $i < 8; $i++) {
                $code .= self::pick(self::UPPER.self::DIGITS);
            }
        } while (Guardian::query()->where('code', $code)->exists());

        return $code;
    }

    private static function pick(string $alphabet): string
    {
        return $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
}
