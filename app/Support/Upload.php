<?php

namespace App\Support;

/**
 * The one size limit for every file upload (v4 D18). PHP's
 * upload_max_filesize and post_max_size must allow it, or the file never
 * reaches SIAKAD.
 */
final class Upload
{
    /**
     * Environment variables that tell PHP where its temporary folder is. `artisan serve` must pass them
     * on, or PHP on Windows has nowhere to store an upload (v7 D42).
     */
    public const TEMP_VARIABLES = ['TEMP', 'TMP', 'TMPDIR'];

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

    /**
     * What stops PHP from receiving files on this server, for the Superadmin (empty when uploads work).
     *
     * @return list<string>
     */
    public static function problems(): array
    {
        $problems = [];

        if (! filter_var(ini_get('file_uploads'), FILTER_VALIDATE_BOOLEAN)) {
            $problems[] = __('PHP has file uploads turned off (file_uploads).');
        }

        $folder = self::temporaryFolder();
        if (! self::canWrite($folder)) {
            $problems[] = __('PHP cannot write to its temporary folder :folder, so no upload arrives. Set upload_tmp_dir in php.ini, or start the server with TEMP set.', ['folder' => $folder]);
        }

        foreach (['upload_max_filesize', 'post_max_size'] as $setting) {
            $value = (string) ini_get($setting);
            $bytes = self::bytes($value);
            if ($bytes > 0 && $bytes < self::maxKb() * 1024) {
                $problems[] = __('PHP :setting is :value, smaller than the upload limit of :size MB.', ['setting' => $setting, 'value' => $value, 'size' => self::maxMb()]);
            }
        }

        return $problems;
    }

    /**
     * Where PHP stores an upload before SIAKAD reads it.
     */
    public static function temporaryFolder(): string
    {
        return (string) (ini_get('upload_tmp_dir') ?: sys_get_temp_dir());
    }

    /**
     * Whether a file can be created in the folder. Tried for real: on Windows is_writable() only reads
     * the read-only flag, so it says yes for C:\Windows.
     */
    public static function canWrite(string $folder): bool
    {
        if (! is_dir($folder)) {
            return false;
        }

        $probe = rtrim($folder, '\\/').DIRECTORY_SEPARATOR.'siakad-'.bin2hex(random_bytes(6)).'.tmp';
        if (@file_put_contents($probe, '') === false) {
            return false;
        }

        @unlink($probe);

        return true;
    }

    /**
     * A php.ini size such as "12M" in bytes (0 means no limit).
     */
    public static function bytes(string $value): int
    {
        $value = trim($value);
        $number = (int) $value;

        return match (strtoupper(substr($value, -1))) {
            'G' => $number * 1024 ** 3,
            'M' => $number * 1024 ** 2,
            'K' => $number * 1024,
            default => $number,
        };
    }
}
