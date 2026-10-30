<?php

namespace App\Support;

use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Border;
use OpenSpout\Common\Entity\Style\BorderName;
use OpenSpout\Common\Entity\Style\BorderPart;
use OpenSpout\Common\Entity\Style\CellVerticalAlignment;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;
use Spatie\SimpleExcel\SimpleExcelWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Builds an .xlsx in a temporary file and returns it as a download that is
 * deleted right after sending (also usable as a Livewire action response).
 *
 * Every sheet gets the same designed look (v4 D24): a green header row in
 * bold white, columns as wide as their content, and the header frozen while
 * scrolling. Templates also get an example row and a "Petunjuk" sheet.
 */
final class SpreadsheetDownload
{
    private const HEADER_COLOR = '2E7D32';

    private const LINE_COLOR = 'C8E6C9';

    /**
     * @param  iterable<int, array<string, mixed>>  $rows
     */
    public static function make(string $filename, iterable $rows): BinaryFileResponse
    {
        $path = self::write([...$rows]);

        return response()->download($path, $filename)->deleteFileAfterSend();
    }

    /**
     * An import template: the column headers (required ones marked *), one
     * example row in grey italics, and a sheet explaining every column.
     *
     * @param  array<string, string>  $columns  header => description
     * @param  array<string, string>  $example  header => example value
     */
    public static function template(string $filename, string $title, array $columns, array $example): BinaryFileResponse
    {
        return response()->download(self::templatePath($title, $columns, $example), $filename)->deleteFileAfterSend();
    }

    /**
     * The template file itself (used for downloads and stored defaults).
     *
     * @param  array<string, string>  $columns
     * @param  array<string, string>  $example
     */
    public static function templatePath(string $title, array $columns, array $example): string
    {
        $header = fn (string $column) => $column.(str_starts_with($columns[$column], __('Required')) ? '*' : '');
        $headers = array_map($header, array_keys($columns));

        $notes = [[__('Column') => '', __('How to fill it in') => $title]];
        foreach ($columns as $column => $description) {
            $notes[] = [__('Column') => $header($column), __('How to fill it in') => $description];
        }
        $notes[] = [__('Column') => '', __('How to fill it in') => __('Columns marked * are required. Keep the column names as they are; replace the grey example row with your data.')];

        return self::write(
            [array_combine($headers, array_map(fn (string $column) => $example[$column] ?? '', array_keys($columns)))],
            exampleRow: true,
            notes: $notes,
        );
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

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, string>>  $notes  rows of an extra "Petunjuk" sheet
     */
    private static function write(array $rows, bool $exampleRow = false, array $notes = []): string
    {
        // System temp, not the private disk: these are transient and must never end up in backups.
        $path = self::directory().DIRECTORY_SEPARATOR.Str::uuid().'.xlsx';

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        $writer = SimpleExcelWriter::create($path)->setHeaderStyle(self::headerStyle());
        self::prepareSheet($writer, $rows, $exampleRow ? __('Template') : __('Data'));

        foreach ($rows as $row) {
            $writer->addRow($row, $exampleRow ? self::exampleStyle() : self::cellStyle());
        }

        if ($notes !== []) {
            $writer->addNewSheetAndMakeItCurrent(__('Instructions'));
            self::prepareSheet($writer, $notes);

            foreach ($notes as $note) {
                $writer->addRow($note, self::cellStyle()->withShouldWrapText(true));
            }
        }

        $writer->close();

        return $path;
    }

    /**
     * Widths from the content (header included) and the frozen header row.
     * Both must be set before the sheet's first row is written.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private static function prepareSheet(SimpleExcelWriter $writer, array $rows, ?string $name = null): void
    {
        $inner = $writer->getWriter();

        if (! $inner instanceof Writer) {
            return;
        }

        if ($name !== null) {
            $writer->nameCurrentSheet($name);
        }

        $sheet = $inner->getCurrentSheet();
        $sheet->setSheetView(new SheetView(freezeRow: 2));

        foreach (array_keys($rows[0] ?? []) as $index => $header) {
            $longest = max(mb_strlen((string) $header), ...array_map(fn (array $row) => mb_strlen((string) ($row[$header] ?? '')), $rows));
            $sheet->setColumnWidth(min(60, max(10, $longest + 3)), $index + 1);
        }
    }

    private static function headerStyle(): Style
    {
        return (new Style)
            ->withFontBold(true)
            ->withFontColor('FFFFFF')
            ->withBackgroundColor(self::HEADER_COLOR)
            ->withCellVerticalAlignment(CellVerticalAlignment::CENTER)
            ->withShouldWrapText(true)
            ->withBorder(self::border());
    }

    private static function cellStyle(): Style
    {
        return (new Style)->withCellVerticalAlignment(CellVerticalAlignment::TOP)->withBorder(self::border());
    }

    private static function exampleStyle(): Style
    {
        return self::cellStyle()->withFontItalic(true)->withFontColor('757575');
    }

    private static function border(): Border
    {
        return new Border(...array_map(
            fn (BorderName $name) => new BorderPart($name, self::LINE_COLOR),
            [BorderName::TOP, BorderName::BOTTOM, BorderName::LEFT, BorderName::RIGHT],
        ));
    }
}
