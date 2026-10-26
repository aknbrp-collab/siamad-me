<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->backups = sys_get_temp_dir().'/siakad-backup-test-'.uniqid();
    $this->private = sys_get_temp_dir().'/siakad-private-test-'.uniqid();

    File::ensureDirectoryExists($this->private.'/documents');
    File::put($this->private.'/documents/ktp.pdf', 'ktp');
    File::put($this->private.'/photo.jpg', 'photo');

    config([
        'siakad.backup.path' => $this->backups,
        'filesystems.disks.local.root' => $this->private,
        'siakad.backup.connection' => 'pgsql',
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->backups);
    File::deleteDirectory($this->private);
});

test('a backup holds the database dump and every private file', function () {
    Process::fake(function (PendingProcess $process) {
        // Stand in for pg_dump writing its --file.
        $file = collect($process->command)->first(fn (string $arg) => str_starts_with($arg, '--file='));
        File::put(substr($file, 7), 'dump');

        return Process::result();
    });

    $this->artisan('siakad:backup')->assertSuccessful();

    $folder = File::directories($this->backups)[0];
    $zip = new ZipArchive;
    $zip->open($folder.'/files.zip');

    expect(File::get($folder.'/database.dump'))->toBe('dump')
        ->and($zip->numFiles)->toBe(2)
        ->and($zip->locateName('documents/ktp.pdf'))->not->toBeFalse();

    Process::assertRan(fn (PendingProcess $process) => $process->command[0] === config('siakad.backup.pg_dump')
        && in_array('--format=custom', $process->command, true));
});

test('a failed dump leaves no partial backup behind', function () {
    Process::fake(fn () => Process::result(errorOutput: 'connection refused', exitCode: 1));

    $this->artisan('siakad:backup')
        ->expectsOutputToContain('pg_dump failed: connection refused')
        ->assertFailed();

    expect(File::directories($this->backups))->toBe([]);
});

test('backups older than the retention period are removed', function () {
    Process::fake();

    $old = $this->backups.'/2026-01-01_020000';
    File::ensureDirectoryExists($old);
    touch($old, now()->subDays(31)->getTimestamp());

    $recent = $this->backups.'/2026-10-20_020000';
    File::ensureDirectoryExists($recent);
    touch($recent, now()->subDays(5)->getTimestamp());

    $this->artisan('siakad:backup')->assertSuccessful();

    expect(File::isDirectory($old))->toBeFalse()
        ->and(File::isDirectory($recent))->toBeTrue()
        ->and(File::directories($this->backups))->toHaveCount(2);
});

test('only PostgreSQL databases are backed up', function () {
    Process::fake();
    config(['siakad.backup.connection' => 'sqlite']);

    $this->artisan('siakad:backup')->assertFailed();

    Process::assertNothingRan();
});

test('the backup runs every day', function () {
    $this->artisan('schedule:list')->expectsOutputToContain('siakad:backup');
});
