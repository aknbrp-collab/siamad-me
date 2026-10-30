<?php

namespace App\Support;

use App\Imports\History\BillHistory;
use App\Imports\History\CashHistory;
use App\Imports\History\GradeHistory;
use App\Imports\History\HistoryImporter;
use App\Imports\History\PlacementHistory;
use App\Imports\Importer;
use App\Imports\Importers;
use App\Models\DocumentTemplate;
use App\Models\LetterType;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpWord\TemplateProcessor;
use RuntimeException;
use Spatie\SimpleExcel\SimpleExcelReader;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * Template slots managed by staff (v4 D24): every downloadable template has a
 * designed built-in default; Admin, Superadmin and the academic monitor may
 * upload a replacement, and go back to the default. Excel replacements must
 * keep the column headers SIAKAD reads; Word replacements may use the
 * placeholders of TeachingTemplates.
 */
final class Templates
{
    /**
     * key => [label, format, group, source]. Source: an importer class, or the teaching kind.
     *
     * @return array<string, array{label: string, format: string, group: string, source: string}>
     */
    public static function slots(): array
    {
        $slots = [];

        foreach (Importers::TYPES as $type => $class) {
            $slots["import.{$type}"] = ['label' => app($class)->label(), 'format' => 'xlsx', 'group' => __('Import'), 'source' => $class];
        }

        foreach (self::HISTORY as $type => $class) {
            $slots["history.{$type}"] = ['label' => app($class)->label(), 'format' => 'xlsx', 'group' => __('Previous-year data'), 'source' => $class];
        }

        $slots['teaching.rps'] = ['label' => __('RPS'), 'format' => 'docx', 'group' => __('Teaching documents'), 'source' => 'rps'];
        $slots['teaching.journal'] = ['label' => __('Teaching journal'), 'format' => 'docx', 'group' => __('Teaching documents'), 'source' => 'journal'];

        // One per letter type (v4 D19); without a replacement the type keeps the built-in PDF layout.
        foreach (LetterType::query()->orderBy('name')->get() as $type) {
            $slots["letter.{$type->id}"] = ['label' => "{$type->name} ({$type->code})", 'format' => 'docx', 'group' => __('Letters'), 'source' => 'letter'];
        }

        return $slots;
    }

    /** @var array<string, class-string<HistoryImporter>> */
    public const HISTORY = [
        'placements' => PlacementHistory::class,
        'grades' => GradeHistory::class,
        'bills' => BillHistory::class,
        'cash' => CashHistory::class,
    ];

    /**
     * @return array{label: string, format: string, group: string, source: string}
     */
    public static function slot(string $key): array
    {
        return self::slots()[$key] ?? abort(404);
    }

    public static function current(string $key): ?DocumentTemplate
    {
        return DocumentTemplate::query()->where('key', $key)->orderByDesc('version')->first();
    }

    /**
     * The file in use: the uploaded replacement, or a freshly built default
     * (a temporary file). Returns [path, temporary?].
     *
     * @return array{0: string, 1: bool}
     */
    public static function file(string $key): array
    {
        $current = self::current($key);

        if ($current?->file_path !== null && Storage::disk('local')->exists($current->file_path)) {
            return [Storage::disk('local')->path($current->file_path), false];
        }

        return [self::defaultFile($key), true];
    }

    public static function download(string $key): BinaryFileResponse
    {
        $slot = self::slot($key);
        [$path, $temporary] = self::file($key);
        $name = $slot['source'] === 'letter' ? 'surat-'.Str::slug($slot['label']) : Str::after($key, '.');
        $response = response()->download($path, "template-{$name}.{$slot['format']}");

        return $temporary ? $response->deleteFileAfterSend() : $response;
    }

    public static function defaultFile(string $key): string
    {
        $slot = self::slot($key);

        if ($slot['source'] === 'letter') {
            return LetterDocuments::defaultFile();
        }

        if ($slot['format'] === 'docx') {
            return TeachingTemplates::defaultFile($slot['source']);
        }

        /** @var Importer|HistoryImporter $importer */
        $importer = app($slot['source']);

        return SpreadsheetDownload::templatePath($slot['label'], $importer->columns(), $importer->example());
    }

    /**
     * Upload a replacement as a new version.
     */
    public static function upload(string $key, mixed $file, User $by): DocumentTemplate
    {
        $slot = self::slot($key);

        Validator::make(['file' => $file], ['file' => ['required', 'file', 'mimes:'.($slot['format'] === 'docx' ? 'docx' : 'xlsx'), Upload::max()]])->validate();

        /** @var UploadedFile $file */
        $slot['format'] === 'docx' ? self::checkWord($file, self::wordPlaceholders($slot)) : self::checkSheet($slot, $file);

        return DB::transaction(function () use ($key, $slot, $file, $by) {
            $version = self::nextVersion($key);
            $path = $file->storeAs('templates/'.Str::slug($key), "v{$version}.{$slot['format']}", 'local');

            if ($path === false) {
                throw new RuntimeException('The template could not be stored.');
            }

            return DocumentTemplate::create([
                'key' => $key,
                'version' => $version,
                'file_path' => $path,
                'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                'uploaded_by' => $by->id,
            ]);
        });
    }

    /**
     * Go back to the built-in default (earlier uploads stay as history).
     */
    public static function revert(string $key, User $by): void
    {
        self::slot($key);

        if (self::current($key)?->file_path === null) {
            return;
        }

        DB::transaction(fn () => DocumentTemplate::create(['key' => $key, 'version' => self::nextVersion($key), 'uploaded_by' => $by->id]));
    }

    private static function nextVersion(string $key): int
    {
        // PostgreSQL refuses FOR UPDATE with MAX(): lock the slot's rows, then take the highest.
        // The first upload of a slot has nothing to lock; the unique (key, version) index catches a race.
        return (int) DocumentTemplate::query()->where('key', $key)->lockForUpdate()->pluck('version')->max() + 1;
    }

    /**
     * An Excel replacement must still have every required column.
     *
     * @param  array{label: string, format: string, group: string, source: string}  $slot
     */
    private static function checkSheet(array $slot, UploadedFile $file): void
    {
        /** @var Importer|HistoryImporter $importer */
        $importer = app($slot['source']);

        try {
            $headers = array_map(fn ($header) => Str::snake(trim(Str::before((string) $header, '*'))), SimpleExcelReader::create($file->getRealPath(), 'xlsx')->getHeaders() ?? []);
        } catch (Throwable) {
            throw ValidationException::withMessages(['file' => __('This file is not an Excel workbook SIAKAD can read.')]);
        }

        $required = array_keys(array_filter($importer->columns(), fn (string $description) => str_starts_with($description, __('Required'))));
        $missing = array_diff($required, $headers);

        if ($missing !== []) {
            throw ValidationException::withMessages(['file' => __('The first row must keep the columns SIAKAD reads. Missing: :columns.', ['columns' => implode(', ', $missing)])]);
        }
    }

    /**
     * Placeholder names a Word slot may use.
     *
     * @param  array{label: string, format: string, group: string, source: string}  $slot
     * @return list<string>
     */
    public static function wordPlaceholders(array $slot): array
    {
        return $slot['source'] === 'letter'
            ? array_keys(LetterDocuments::placeholders())
            : [...array_keys(TeachingTemplates::placeholders()), ...array_keys(TeachingTemplates::journalRow())];
    }

    /**
     * A Word replacement must open, and may only use known placeholders.
     *
     * @param  list<string>  $known
     */
    private static function checkWord(UploadedFile $file, array $known): void
    {
        try {
            $variables = (new TemplateProcessor($file->getRealPath()))->getVariables();
        } catch (Throwable) {
            throw ValidationException::withMessages(['file' => __('This file is not a Word document SIAKAD can read.')]);
        }

        $unknown = array_diff(array_unique($variables), $known);

        if ($unknown !== []) {
            throw ValidationException::withMessages(['file' => __('Unknown placeholders: :names.', ['names' => implode(', ', array_map(fn ($name) => '${'.$name.'}', $unknown))])]);
        }
    }
}
