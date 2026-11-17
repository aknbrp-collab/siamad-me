<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Backups;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Symfony\Component\Finder\SplFileInfo;
use ZipArchive;

#[Signature('siakad:backup {--keep= : Days to keep old backups (default: config siakad.backup.retention_days)} {--by= : Made by hand by this user (id); kept until deleted}')]
#[Description('Back up the database (pg_dump) and the private files, then remove backups older than the retention period')]
class BackupCommand extends Command
{
    public function handle(): int
    {
        $root = (string) config('siakad.backup.path');
        $folder = $root.DIRECTORY_SEPARATOR.now()->format('Y-m-d_His');

        File::ensureDirectoryExists($folder);

        // An empty database (a new server) has no users yet.
        $by = $this->option('by') !== null && Schema::hasTable('users') ? User::query()->find((int) $this->option('by')) : null;

        try {
            $this->dumpDatabase($folder.DIRECTORY_SEPARATOR.'database.dump');
            $files = $this->zipPrivateFiles($folder.DIRECTORY_SEPARATOR.'files.zip');
            // When, by whom, the database version, row counts and checksums (D50).
            Backups::writeManifest($folder, $by !== null ? Backups::MANUAL : Backups::SCHEDULED, $by, $files);
        } catch (RuntimeException $exception) {
            // A half-written backup must not look like a good one.
            File::deleteDirectory($folder);
            $this->components->error($exception->getMessage());
            logger()->error('SIAKAD backup failed', ['error' => $exception->getMessage()]);

            if ($by !== null) {
                Backups::finish(false, $exception->getMessage());
            }

            return self::FAILURE;
        }

        $this->components->info("Backup written to {$folder} ({$files} private files).");

        // Started from the Data page: the page shows it is done.
        if ($by !== null) {
            Backups::finish(true, basename($folder));
        }

        $removed = $this->prune($root, (int) ($this->option('keep') ?? config('siakad.backup.retention_days')));

        if ($removed > 0) {
            $this->components->info("Removed {$removed} backup(s) past the retention period.");
        }

        return self::SUCCESS;
    }

    private function dumpDatabase(string $target): void
    {
        $connection = config('database.connections.'.config('siakad.backup.connection'));

        if (($connection['driver'] ?? null) !== 'pgsql') {
            throw new RuntimeException('Database backups need PostgreSQL (the current connection is '.($connection['driver'] ?? 'unknown').').');
        }

        // Custom format: compressed, and restorable table by table with pg_restore.
        $result = Process::timeout(900)
            ->env(['PGPASSWORD' => (string) ($connection['password'] ?? '')])
            ->run([
                (string) config('siakad.backup.pg_dump'),
                '--format=custom',
                '--no-owner',
                '--host='.$connection['host'],
                '--port='.$connection['port'],
                '--username='.$connection['username'],
                '--file='.$target,
                (string) $connection['database'],
            ]);

        if ($result->failed()) {
            throw new RuntimeException('pg_dump failed: '.trim($result->errorOutput() ?: $result->output()));
        }

        if (! File::exists($target) || File::size($target) === 0) {
            throw new RuntimeException('pg_dump wrote no dump file.');
        }
    }

    /**
     * Zip storage/app/private (the "local" disk) and return the number of files.
     */
    private function zipPrivateFiles(string $target): int
    {
        $source = (string) config('filesystems.disks.local.root');
        $zip = new ZipArchive;

        if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Cannot create {$target}.");
        }

        $files = File::isDirectory($source) ? File::allFiles($source) : [];

        foreach ($files as $file) {
            /** @var SplFileInfo $file */
            $zip->addFile($file->getPathname(), str_replace('\\', '/', $file->getRelativePathname()));
        }

        // An empty archive is not written to disk; keep a marker so the run is complete.
        if ($files === []) {
            $zip->addFromString('.empty', '');
        }

        if (! $zip->close()) {
            throw new RuntimeException("Cannot write {$target}.");
        }

        return count($files);
    }

    /**
     * Delete scheduled backup folders older than the given number of days; backups made by hand stay
     * until the Superadmin deletes them (D50).
     */
    private function prune(string $root, int $days): int
    {
        $cutoff = now()->subDays(max($days, 1))->getTimestamp();
        $removed = 0;

        foreach (File::directories($root) as $directory) {
            if ((Backups::manifest($directory)['kind'] ?? Backups::SCHEDULED) === Backups::MANUAL) {
                continue;
            }

            if (File::lastModified($directory) < $cutoff) {
                File::deleteDirectory($directory);
                $removed++;
            }
        }

        return $removed;
    }
}
