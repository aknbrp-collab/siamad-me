<?php

namespace App\Support;

use Illuminate\Support\Str;
use Spatie\SimpleExcel\SimpleExcelWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Builds an .xlsx in a temporary file and returns it as a download that is
 * deleted right after sending (also usable as a Livewire action response).
 */
final class SpreadsheetDownload
{
    /**
     * @param  iterable<int, array<string, mixed>>  $rows
     */
    public static function make(string $filename, iterable $rows): BinaryFileResponse
    {
        // System temp, not the private disk: these are transient and must never end up in backups.
        $path = self::directory().DIRECTORY_SEPARATOR.Str::uuid().'.xlsx';

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        $writer = SimpleExcelWriter::create($path);

        foreach ($rows as $row) {
            $writer->addRow($row);
        }

        $writer->close();

        return response()->download($path, $filename)->deleteFileAfterSend();
    }

    /**
     * Where the temporary .xlsx files are built.
     */
    public static function directory(): string
    {
        return sys_get_temp_dir().DIRECTORY_SEPARATOR.'siakad-downloads';
    }

    /**
     * The credentials sheet given to new users. Generated on demand only; the
     * temporary passwords are never written to the database or kept on disk.
     *
     * @param  list<CreatedAccount>  $accounts
     */
    public static function credentials(string $filename, array $accounts): BinaryFileResponse
    {
        return self::make($filename, array_map(fn (CreatedAccount $account) => [
            __('Name') => $account->name,
            __('Login ID') => $account->username,
            __('Temporary password') => $account->password,
            __('Login at') => url('/login'),
        ], $accounts));
    }
}
