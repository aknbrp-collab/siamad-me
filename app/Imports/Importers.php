<?php

namespace App\Imports;

use DateTimeInterface;
use Illuminate\Support\Str;
use Spatie\SimpleExcel\SimpleExcelReader;

final class Importers
{
    /**
     * @var array<string, class-string<Importer>>
     */
    public const TYPES = [
        'students' => StudentImporter::class,
        'teachers' => TeacherImporter::class,
        'subjects' => SubjectImporter::class,
    ];

    public static function make(string $type): Importer
    {
        abort_unless(isset(self::TYPES[$type]), 404);

        return app(self::TYPES[$type]);
    }

    /**
     * Read an .xlsx or .csv into rows keyed by snake_case header. Empty rows
     * are skipped; dates become Y-m-d and whole numbers lose any ".0".
     *
     * @return list<array<string, string>>
     */
    public static function read(string $path, string $extension): array
    {
        $rows = [];

        SimpleExcelReader::create($path, $extension)
            ->formatHeadersUsing(fn (string $header) => Str::snake(trim(Str::before($header, '*'))))
            ->getRows()
            ->each(function (array $row) use (&$rows) {
                $values = array_map(fn (mixed $value) => match (true) {
                    $value instanceof DateTimeInterface => $value->format('Y-m-d'),
                    is_float($value) && floor($value) === $value => (string) (int) $value,
                    is_bool($value) => $value ? '1' : '0',
                    default => trim((string) $value),
                }, $row);

                if (implode('', $values) !== '') {
                    $rows[] = $values;
                }
            });

        return $rows;
    }

    /**
     * Read "ya/tidak", "yes/no", "1/0" as a boolean; blank stays unknown.
     */
    public static function yesNo(?string $value): ?bool
    {
        return match (Str::lower(trim((string) $value))) {
            'ya', 'y', 'yes', '1', 'true', 'hidup' => true,
            'tidak', 't', 'no', 'n', '0', 'false', 'wafat', 'meninggal' => false,
            default => null,
        };
    }

    /**
     * Blank strings become null so optional columns validate as empty.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function nullBlanks(array $input): array
    {
        return array_map(fn (mixed $value) => $value === '' ? null : $value, $input);
    }
}
