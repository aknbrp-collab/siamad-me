<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;
use ZipArchive;

/**
 * Restoring a backup (data-manage D51): checking an archive before anything changes, taking in an uploaded
 * one, and the state of a running restore. The restore itself is `siakad:restore` (RestoreCommand), run in
 * its own process; its state lives next to the backups, outside the database and the files it replaces.
 *
 * @phpstan-import-type Backup from Backups
 */
final class Restores
{
    /** What the Superadmin types to confirm. */
    public const PHRASE = 'PULIHKAN';

    /** The parts of a backup archive. */
    public const PARTS = ['database.dump', 'files.zip', Backups::MANIFEST];

    private const STATE = '.restore.json';

    public static function maxMb(): int
    {
        return (int) config('siakad.backup.restore_max_mb', 512);
    }

    public static function pgRestore(): string
    {
        return (string) config('siakad.backup.pg_restore');
    }

    /**
     * Whether pg_restore can be run here.
     */
    public static function toolsReady(): bool
    {
        try {
            return Process::timeout(20)->run([self::pgRestore(), '--version'])->successful();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * What stops this backup from being restored (empty when it can be).
     *
     * @param  Backup  $backup
     * @return list<string>
     */
    public static function problems(array $backup): array
    {
        $manifest = $backup['manifest'];

        if ($manifest === null || ($manifest['format'] ?? null) !== 'siakad-backup') {
            return [__('This backup has no description (manifest.json), so it cannot be checked. Restore it by hand (OPERATIONS.md).')];
        }

        $problems = [];

        foreach (['database.dump', 'files.zip'] as $part) {
            $file = $backup['path'].DIRECTORY_SEPARATOR.$part;

            if (! File::exists($file)) {
                $problems[] = __(':file is missing.', ['file' => $part]);
            } elseif (hash_file('sha256', $file) !== ($manifest['files'][$part]['sha256'] ?? null)) {
                $problems[] = __(':file is damaged (it does not match its checksum).', ['file' => $part]);
            }
        }

        $migration = $manifest['last_migration'] ?? null;
        $known = collect(File::files(database_path('migrations')))->map(fn ($file) => $file->getFilenameWithoutExtension());

        if (! is_string($migration) || ! $known->contains($migration)) {
            $problems[] = __('This backup comes from a newer SIAKAD than this one. Update SIAKAD first.');
        }

        return $problems;
    }

    /**
     * Take in an uploaded backup .zip as a new backup in the list (checked; refused when it fails).
     *
     * @return Backup
     */
    public static function import(UploadedFile $file, User $by): array
    {
        $zip = new ZipArchive;

        if ($zip->open($file->getRealPath()) !== true) {
            throw ValidationException::withMessages(['archive' => __('This file is not a SIAKAD backup (.zip).')]);
        }

        $names = collect(range(0, $zip->numFiles - 1))->map(fn (int $index) => (string) $zip->getNameIndex($index));

        // Exactly the three parts, nothing in folders (no paths to write elsewhere).
        if ($names->sort()->values()->all() !== collect(self::PARTS)->sort()->values()->all()) {
            $zip->close();

            throw ValidationException::withMessages(['archive' => __('This file is not a SIAKAD backup: it must hold :parts and nothing else.', ['parts' => implode(', ', self::PARTS)])]);
        }

        $name = now()->format('Y-m-d_His');
        while (File::isDirectory(Backups::root().DIRECTORY_SEPARATOR.$name)) {
            $name = now()->addSecond()->format('Y-m-d_His');
            sleep(1);
        }
        $folder = Backups::root().DIRECTORY_SEPARATOR.$name;
        File::ensureDirectoryExists($folder);
        $zip->extractTo($folder, self::PARTS);
        $zip->close();

        // Kept as a backup made by hand (so it can be deleted), noting where it came from.
        $manifest = Backups::manifest($folder) ?? [];
        File::put($folder.DIRECTORY_SEPARATOR.Backups::MANIFEST, (string) json_encode([
            ...$manifest,
            'kind' => Backups::MANUAL,
            'made_by' => $manifest['made_by'] ?? null,
            'imported' => ['at' => now()->toIso8601String(), 'by' => ['id' => $by->id, 'name' => $by->name], 'file' => $file->getClientOriginalName()],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $backup = Backups::find($name);
        $problems = self::problems($backup);

        if ($problems !== []) {
            File::deleteDirectory($folder);

            throw ValidationException::withMessages(['archive' => $problems]);
        }

        Backups::audit('backup.uploaded', $name, ['file' => $file->getClientOriginalName()]);

        return $backup;
    }

    // --- The state of a restore -----------------------------------------------------------------------

    /**
     * Record that a restore of $backup starts; returns the token its status link carries.
     */
    public static function begin(string $backup, ?string $by): string
    {
        $token = Str::random(40);
        self::write([
            'token' => $token,
            'backup' => $backup,
            'by' => $by,
            'status' => 'running',
            'step' => 'starting',
            'message' => null,
            'safety_backup' => null,
            'started_at' => now()->toIso8601String(),
            'finished_at' => null,
        ]);

        return $token;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function state(): ?array
    {
        $path = Backups::root().DIRECTORY_SEPARATOR.self::STATE;
        $data = File::exists($path) ? json_decode(File::get($path), true) : null;

        return is_array($data) ? $data : null;
    }

    /**
     * Whether a restore is running now (one that died hours ago does not count).
     */
    public static function running(): bool
    {
        $state = self::state();

        return ($state['status'] ?? null) === 'running'
            && now()->subHours(3)->lt((string) $state['started_at']);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public static function update(array $changes): void
    {
        self::write([...(self::state() ?? []), ...$changes]);
    }

    /**
     * The state for its status link (without the token itself).
     *
     * @return array<string, mixed>|null
     */
    public static function forToken(string $token): ?array
    {
        $state = self::state();

        if ($state === null || ! hash_equals((string) ($state['token'] ?? ''), $token)) {
            return null;
        }

        unset($state['token']);

        return $state;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private static function write(array $state): void
    {
        File::ensureDirectoryExists(Backups::root());
        File::put(Backups::root().DIRECTORY_SEPARATOR.self::STATE, (string) json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
