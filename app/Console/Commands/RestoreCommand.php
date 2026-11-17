<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Backups;
use App\Support\DatabaseRestore;
use App\Support\Restores;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;
use ZipArchive;

#[Signature('siakad:restore {backup : The backup folder, e.g. 2026-11-08_020000} {--by= : The Superadmin who asked for it (id)}')]
#[Description('Replace the database and the private files with a backup (a fresh backup of the current state is made first)')]
class RestoreCommand extends Command
{
    public function handle(DatabaseRestore $database): int
    {
        $name = (string) $this->argument('backup');
        // An empty database (a new server) has no users yet.
        $by = $this->option('by') !== null && Schema::hasTable('users') ? User::query()->find((int) $this->option('by')) : null;

        if (preg_match(Backups::NAME, $name) !== 1 || ! File::isDirectory(Backups::root().DIRECTORY_SEPARATOR.$name)) {
            $this->components->error("There is no backup {$name}.");

            return self::FAILURE;
        }

        $backup = Backups::find($name);
        $problems = Restores::problems($backup);

        if ($problems !== []) {
            $this->failed(implode(' ', $problems), null);

            return self::FAILURE;
        }

        // Started by hand on the server: record it like the page does.
        if (! Restores::running()) {
            Restores::begin($name, $by?->name);
        }

        $swapped = null;

        try {
            // 1. A backup of the current state, so this can be undone.
            $this->step('safety_backup');
            if (Artisan::call('siakad:backup', array_filter(['--by' => $by?->id])) !== 0) {
                throw new RuntimeException('The backup of the current state failed, so nothing was restored: '.trim(Artisan::output()));
            }
            $safety = Backups::all()->first()['name'] ?? null;
            Restores::update(['safety_backup' => $safety]);

            // 2. Nobody works in SIAKAD meanwhile.
            $this->step('maintenance');
            Artisan::call('down', ['--retry' => 30]);

            // 3. The database (all or nothing), 4. the files.
            $this->step('database');
            $database->restore($backup['path'].DIRECTORY_SEPARATOR.'database.dump');

            $this->step('files');
            $swapped = $this->replaceFiles($backup['path'].DIRECTORY_SEPARATOR.'files.zip');

            // 5. Changes since the backup's SIAKAD, and a clean start.
            $this->step('migrate');
            Artisan::call('migrate', ['--force' => true]);
            foreach (['sessions', 'jobs'] as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->delete();
                }
            }
            Artisan::call('cache:clear');

            $this->audit($name, $safety, $by);
            Artisan::call('up');

            File::deleteDirectory($swapped);

            Restores::update(['status' => 'done', 'step' => 'done', 'finished_at' => now()->toIso8601String()]);
            $this->components->info("Restored {$name}. Everyone signs in again.");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            Artisan::call('up');
            $message = $exception->getMessage();

            if ($swapped !== null) {
                $message .= ' The files from before the restore are kept in '.$swapped.'.';
            }

            $this->failed($message, Restores::state()['safety_backup'] ?? null);

            return self::FAILURE;
        }
    }

    private function step(string $step): void
    {
        Restores::update(['step' => $step]);
        $this->components->task(ucfirst(str_replace('_', ' ', $step)));
    }

    private function failed(string $message, ?string $safety): void
    {
        if (Restores::state() !== null) {
            Restores::update(['status' => 'failed', 'message' => $message, 'finished_at' => now()->toIso8601String()]);
        }

        $this->components->error($message.($safety ? " The state before is in backup {$safety}." : ''));
        logger()->error('SIAKAD restore failed', ['error' => $message, 'safety_backup' => $safety]);
    }

    /**
     * Unpack the backup's files next to the private folder, then swap the two; returns the old folder.
     */
    private function replaceFiles(string $archive): string
    {
        $root = rtrim((string) config('filesystems.disks.local.root'), '\\/');
        $stamp = now()->format('Ymd_His');
        $incoming = "{$root}-restoring-{$stamp}";
        $before = "{$root}-before-restore-{$stamp}";

        $zip = new ZipArchive;
        if ($zip->open($archive) !== true || ! $zip->extractTo($incoming)) {
            throw new RuntimeException('The files of the backup could not be unpacked.');
        }
        $zip->close();
        File::delete($incoming.DIRECTORY_SEPARATOR.'.empty');

        if (File::isDirectory($root) && ! rename($root, $before)) {
            File::deleteDirectory($incoming);

            throw new RuntimeException('The current files could not be moved aside.');
        }

        if (! rename($incoming, $root)) {
            rename($before, $root);

            throw new RuntimeException('The files of the backup could not be put in place.');
        }

        return $before;
    }

    /**
     * Written into the restored database, so the restore is in the audit log that stays.
     */
    private function audit(string $name, ?string $safety, ?User $by): void
    {
        AuditLog::create([
            'user_id' => $by !== null && Schema::hasTable('users') && User::query()->whereKey($by->id)->exists() ? $by->id : null,
            'auditable_type' => 'Backup',
            'auditable_id' => $name,
            'event' => 'backup.restored',
            'new_values' => ['by' => $by?->name, 'safety_backup' => $safety],
        ]);
    }
}
