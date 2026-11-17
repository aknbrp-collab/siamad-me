<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * The backups in siakad.backup.path (D50): each a folder named by its time, with database.dump, files.zip
 * and manifest.json. Listed, downloaded and (when made by hand) deleted on the Superadmin's Data page.
 *
 * @phpstan-type Backup array{name: string, path: string, created_at: \Carbon\CarbonInterface, kind: string, made_by: string|null, size: int, manifest: array<string, mixed>|null}
 */
final class Backups
{
    public const MANIFEST = 'manifest.json';

    /** Made by the daily schedule (removed after the retention period). */
    public const SCHEDULED = 'scheduled';

    /** Made by hand (kept until the Superadmin deletes it). */
    public const MANUAL = 'manual';

    /** A backup folder name: its time, e.g. 2026-11-08_020000. */
    public const NAME = '/^\d{4}-\d{2}-\d{2}_\d{6}$/';

    private const RUNNING = '.running.json';

    private const LAST = '.last.json';

    /** A run that has not finished after this long is treated as stopped. */
    private const STALE_HOURS = 3;

    public static function root(): string
    {
        return (string) config('siakad.backup.path');
    }

    /**
     * Every backup, newest first.
     *
     * @return Collection<int, Backup>
     */
    public static function all(): Collection
    {
        if (! File::isDirectory(self::root())) {
            return collect();
        }

        return collect(File::directories(self::root()))
            ->filter(fn (string $path) => preg_match(self::NAME, basename($path)) === 1)
            ->map(fn (string $path) => self::describe($path))
            ->sortByDesc('name')
            ->values();
    }

    /**
     * One backup by its folder name, or a 404.
     *
     * @return Backup
     */
    public static function find(string $name): array
    {
        $path = self::root().DIRECTORY_SEPARATOR.$name;
        abort_unless(preg_match(self::NAME, $name) === 1 && File::isDirectory($path), 404);

        return self::describe($path);
    }

    /**
     * @return Backup
     */
    private static function describe(string $path): array
    {
        $manifest = self::manifest($path);
        $size = collect(['database.dump', 'files.zip', self::MANIFEST])
            ->sum(fn (string $file) => File::exists($path.DIRECTORY_SEPARATOR.$file) ? File::size($path.DIRECTORY_SEPARATOR.$file) : 0);

        return [
            'name' => basename($path),
            'path' => $path,
            'created_at' => isset($manifest['created_at']) ? Carbon::parse((string) $manifest['created_at']) : (Carbon::createFromFormat('Y-m-d_His', basename($path)) ?: now()),
            // Backups from before the manifest were all made by the schedule.
            'kind' => (string) ($manifest['kind'] ?? self::SCHEDULED),
            'made_by' => isset($manifest['made_by']['name']) ? (string) $manifest['made_by']['name'] : null,
            'size' => (int) $size,
            'manifest' => $manifest,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function manifest(string $path): ?array
    {
        $file = $path.DIRECTORY_SEPARATOR.self::MANIFEST;
        $data = File::exists($file) ? json_decode(File::get($file), true) : null;

        return is_array($data) ? $data : null;
    }

    /**
     * Describe a finished backup: when, by whom, the database version, row counts and checksums.
     *
     * @return array<string, mixed>
     */
    public static function writeManifest(string $folder, string $kind, ?User $by, int $privateFiles): array
    {
        $file = fn (string $name) => [
            'size' => File::size($folder.DIRECTORY_SEPARATOR.$name),
            'sha256' => hash_file('sha256', $folder.DIRECTORY_SEPARATOR.$name),
        ];

        $manifest = [
            'format' => 'siakad-backup',
            'format_version' => 1,
            'created_at' => now()->toIso8601String(),
            'kind' => $kind,
            'made_by' => $by ? ['id' => $by->id, 'name' => $by->name] : null,
            'app' => (string) config('app.name'),
            // The newest migration: a restore refuses backups from a newer database (D51).
            'last_migration' => self::lastMigration(),
            'tables' => self::rowCounts(),
            'files' => [
                'database.dump' => $file('database.dump'),
                'files.zip' => [...$file('files.zip'), 'count' => $privateFiles],
            ],
        ];

        File::put($folder.DIRECTORY_SEPARATOR.self::MANIFEST, (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $manifest;
    }

    public static function lastMigration(): ?string
    {
        return Schema::hasTable('migrations') ? DB::table('migrations')->orderByDesc('id')->value('migration') : null;
    }

    /**
     * Rows per table, for the list and to compare after a restore.
     *
     * @return array<string, int>
     */
    public static function rowCounts(): array
    {
        return collect(Schema::getTables())
            ->reject(fn (array $table) => in_array($table['name'], ['migrations'], true))
            ->mapWithKeys(fn (array $table) => [(string) $table['name'] => DB::table($table['name'])->count()])
            ->sortKeys()
            ->all();
    }

    /**
     * One .zip of a backup to download (a temporary file). The parts are compressed already, so they
     * are stored as they are.
     */
    public static function archive(string $name): string
    {
        $backup = self::find($name);
        $directory = SpreadsheetDownload::directory();
        File::ensureDirectoryExists($directory);
        $target = $directory.DIRECTORY_SEPARATOR.Str::uuid().'.zip';

        $zip = new ZipArchive;
        if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Cannot create {$target}.");
        }

        foreach (['database.dump', 'files.zip', self::MANIFEST] as $part) {
            $file = $backup['path'].DIRECTORY_SEPARATOR.$part;
            if (File::exists($file)) {
                $zip->addFile($file, $part);
                $zip->setCompressionName($part, ZipArchive::CM_STORE);
            }
        }

        if (! $zip->close()) {
            throw new RuntimeException("Cannot write {$target}.");
        }

        return $target;
    }

    /**
     * Delete a backup made by hand (scheduled ones go after the retention period).
     */
    public static function delete(string $name): void
    {
        $backup = self::find($name);
        abort_unless($backup['kind'] === self::MANUAL, 403);

        File::deleteDirectory($backup['path']);
    }

    /**
     * Note a backup action in the audit log (made, downloaded, deleted, restored …).
     *
     * @param  array<string, mixed>  $values
     */
    public static function audit(string $event, string $name, array $values = []): void
    {
        AuditLog::create([
            'user_id' => auth()->id(),
            'auditable_type' => 'Backup',
            'auditable_id' => $name,
            'event' => $event,
            'new_values' => $values ?: null,
            'ip_address' => request()->ip(),
            'user_agent' => Str::limit((string) request()->userAgent(), 500, ''),
        ]);
    }

    // --- A backup started from the Data page, running after the response --------------------------------

    /**
     * The backup running now (started from the page), or null.
     *
     * @return array{started_at: string, by: string|null}|null
     */
    public static function running(): ?array
    {
        $data = self::readState(self::RUNNING);

        if ($data === null) {
            return null;
        }

        // A run that died (server restart) must not block the page for ever.
        if (Carbon::parse((string) $data['started_at'])->lt(now()->subHours(self::STALE_HOURS))) {
            File::delete(self::root().DIRECTORY_SEPARATOR.self::RUNNING);

            return null;
        }

        /** @var array{started_at: string, by: string|null} $data */
        return $data;
    }

    public static function markRunning(?User $by): void
    {
        File::ensureDirectoryExists(self::root());
        File::put(self::root().DIRECTORY_SEPARATOR.self::RUNNING, (string) json_encode(['started_at' => now()->toIso8601String(), 'by' => $by?->name]));
    }

    public static function finish(bool $ok, ?string $message): void
    {
        File::delete(self::root().DIRECTORY_SEPARATOR.self::RUNNING);
        File::put(self::root().DIRECTORY_SEPARATOR.self::LAST, (string) json_encode(['ok' => $ok, 'message' => $message, 'finished_at' => now()->toIso8601String()]));
    }

    /**
     * How the last backup started from the page ended.
     *
     * @return array{ok: bool, message: string|null, finished_at: string}|null
     */
    public static function last(): ?array
    {
        /** @var array{ok: bool, message: string|null, finished_at: string}|null */
        return self::readState(self::LAST);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function readState(string $file): ?array
    {
        $path = self::root().DIRECTORY_SEPARATOR.$file;
        $data = File::exists($path) ? json_decode(File::get($path), true) : null;

        return is_array($data) ? $data : null;
    }
}
