<?php

namespace App\Support;

/**
 * The one size limit for every file upload (v4 D18). PHP's
 * upload_max_filesize and post_max_size must allow it, or the file never
 * reaches SIAKAD.
 */
final class Upload
{
    public static function maxKb(): int
    {
        return (int) config('siakad.uploads.max_kb', 10240);
    }

    public static function maxMb(): int
    {
        return intdiv(self::maxKb(), 1024);
    }

    /**
     * The "max" validation rule for a file.
     */
    public static function max(): string
    {
        return 'max:'.self::maxKb();
    }
}
