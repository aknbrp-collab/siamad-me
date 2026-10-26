<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Symfony\Component\Finder\SplFileInfo;
use ZipArchive;

#[Signature('siakad:backup {--keep= : Days to keep old backups (default: config siakad.backup.retention_days)}')]
#[Description('Back up the database (pg_dump) and the private files, then remove backups older than the retention period')]
class BackupCommand extends Command
{
    public function handle(): int
    {
        $root = (string) config('siakad.backup.path');
        $folder = $root.DIRECTORY_SEPARATOR.now()->format('Y-m-d_His');

        File::ensureDirectoryExists($folder);

        try {
            $this->dumpDatabase($folder.DIRECTORY_SEPARATOR.'database.dump');
            $files = $this->zipPrivateFiles($folder.DIRECTORY_SEPARATOR.'files.zip');
        } catch (RuntimeException $exception) {
            // A half-written backup must not look like a good one.
            File::deleteDirectory($folder);
            $this->components->error($exception->getMessage());
            logger()->error('SIAKAD backup failed', ['error' => $exception->getMessage()]);

            return self::FAILURE;
        }

        $this->components->info("Backup written to {$folder} ({$files} private files).");

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
     * Delete backup folders older than the given number of days.
     */
    private function prune(string $root, int $days): int
    {
        $cutoff = now()->subDays(max($days, 1))->getTimestamp();
        $removed = 0;

        foreach (File::directories($root) as $directory) {
            if (File::lastModified($directory) < $cutoff) {
                File::deleteDirectory($directory);
                $removed++;
            }
        }

        return $removed;
    }
}
