<?php

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\BackgroundCommand;
use App\Support\Backups;
use App\Support\DatabaseRestore;
use App\Support\Restores;
use Illuminate\Http\UploadedFile;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * data-manage D51: restoring a backup from the Superadmin's Data page.
 */

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/siakad-restore-test-'.uniqid();
    $this->private = sys_get_temp_dir().'/siakad-restore-private-'.uniqid();
    File::ensureDirectoryExists($this->private);
    File::put($this->private.'/old.txt', 'before');

    config([
        'siakad.backup.path' => $this->root,
        'filesystems.disks.local.root' => $this->private,
        'siakad.backup.connection' => 'pgsql',
        // Maintenance mode in memory, so other tests running at the same time are not affected.
        'app.maintenance.driver' => 'cache',
        'app.maintenance.store' => 'array',
    ]);

    $this->superadmin = User::factory()->withRole(Role::Superadmin)->create(['name' => 'Pak Super']);
});

afterEach(function () {
    File::deleteDirectory($this->root);
    foreach (File::glob($this->private.'*') as $folder) {
        File::deleteDirectory($folder);
    }
});

/**
 * A backup folder whose files.zip holds new.txt, with a manifest.
 */
function restorableBackup(string $name, array $manifestChanges = []): string
{
    $folder = config('siakad.backup.path').DIRECTORY_SEPARATOR.$name;
    File::ensureDirectoryExists($folder);
    File::put($folder.'/database.dump', 'dump');
    $zip = new ZipArchive;
    $zip->open($folder.'/files.zip', ZipArchive::CREATE);
    $zip->addFromString('documents/new.txt', 'restored');
    $zip->close();
    $manifest = Backups::writeManifest($folder, Backups::MANUAL, null, 1);

    if ($manifestChanges !== []) {
        File::put($folder.'/manifest.json', (string) json_encode([...$manifest, ...$manifestChanges]));
    }

    return $folder;
}

function restoreFakes(?Closure $restore = null): object
{
    $database = new class($restore) extends DatabaseRestore
    {
        public array $restored = [];

        public function __construct(private ?Closure $behaviour) {}

        public function restore(string $dump): void
        {
            $this->restored[] = $dump;
            if ($this->behaviour) {
                ($this->behaviour)();
            }
        }
    };
    app()->instance(DatabaseRestore::class, $database);

    // The backup of the current state: pg_dump writes its file.
    Process::fake(function (PendingProcess $process) {
        $file = collect($process->command)->first(fn (string $arg) => str_starts_with($arg, '--file='));
        if ($file !== null) {
            File::put(substr($file, 7), 'dump');
        }

        return Process::result('pg_restore (PostgreSQL) 17.0');
    });

    return $database;
}

test('a complete backup from this SIAKAD can be restored; damaged or newer ones cannot', function () {
    restorableBackup('2026-11-01_020000');
    expect(Restores::problems(Backups::find('2026-11-01_020000')))->toBe([]);

    $damaged = restorableBackup('2026-11-02_020000');
    File::put($damaged.'/database.dump', 'tampered');
    expect(Restores::problems(Backups::find('2026-11-02_020000')))->toHaveCount(1)
        ->and(Restores::problems(Backups::find('2026-11-02_020000'))[0])->toContain('database.dump');

    restorableBackup('2026-11-03_020000', ['last_migration' => '2099_01_01_000000_from_the_future']);
    expect(Restores::problems(Backups::find('2026-11-03_020000'))[0])->toContain(__('This backup comes from a newer SIAKAD than this one. Update SIAKAD first.'));

    File::ensureDirectoryExists($this->root.'/2026-11-04_020000');
    expect(Restores::problems(Backups::find('2026-11-04_020000')))->toHaveCount(1);
});

test('an uploaded backup .zip joins the list; anything else is refused and leaves nothing behind', function () {
    $source = restorableBackup('2026-11-01_020000');
    $this->actingAs($this->superadmin);
    $zipOf = function (array $parts) {
        $path = sys_get_temp_dir().'/'.uniqid('upload').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        foreach ($parts as $name => $file) {
            $zip->addFile($file, $name);
        }
        $zip->close();

        return new UploadedFile($path, 'siakad-backup.zip', 'application/zip', null, true);
    };
    $parts = ['database.dump' => $source.'/database.dump', 'files.zip' => $source.'/files.zip', 'manifest.json' => $source.'/manifest.json'];

    $backup = Restores::import($zipOf($parts), $this->superadmin);
    expect($backup['kind'])->toBe(Backups::MANUAL)
        ->and($backup['manifest']['imported']['by']['name'])->toBe('Pak Super')
        ->and(Restores::problems($backup))->toBe([]);

    $before = Backups::all()->count();
    expect(fn () => Restores::import($zipOf([...$parts, 'evil.php' => $source.'/database.dump']), $this->superadmin))->toThrow(ValidationException::class);
    File::put($source.'/database.dump', 'tampered');
    expect(fn () => Restores::import($zipOf($parts), $this->superadmin))->toThrow(ValidationException::class);
    expect(Backups::all()->count())->toBe($before);
});

test('restoring asks for the password and the phrase, then runs in its own process', function () {
    restorableBackup('2026-11-01_020000');
    restoreFakes();
    $started = [];
    app()->instance(BackgroundCommand::class, new class($started) extends BackgroundCommand
    {
        public function __construct(public array &$started) {}

        public function start(string $command, array $arguments = []): void
        {
            $this->started[] = [$command, $arguments];
        }
    });
    $this->actingAs($this->superadmin);

    $page = Livewire::test('pages::superadmin.data')
        ->call('chooseRestore', '2026-11-01_020000')
        ->assertSeeHtml('data-test="restore-ok"')
        ->set('password', 'wrong')->set('phrase', 'PULIHKAN')
        ->call('startRestore')->assertHasErrors('password')
        ->set('password', 'password')->set('phrase', 'pulihkan')
        ->call('startRestore')->assertHasErrors('phrase')
        ->set('phrase', 'PULIHKAN')
        ->call('startRestore')->assertHasNoErrors()
        ->assertSeeHtml('data-test="restore-progress"');

    expect($started)->toBe([['siakad:restore', ['2026-11-01_020000', '--by='.$this->superadmin->id]]])
        ->and(Restores::running())->toBeTrue();

    // The status link answers without login, and only to its token.
    app('auth')->forgetGuards();
    $this->getJson(route('restore.status', $page->get('restoreToken')))->assertOk()->assertJsonPath('status', 'running');
    $this->getJson(route('restore.status', str_repeat('x', 40)))->assertNotFound();
});

test('a backup that fails its checks offers no restore', function () {
    restorableBackup('2026-11-03_020000', ['last_migration' => '2099_01_01_000000_from_the_future']);
    restoreFakes();
    $this->actingAs($this->superadmin);

    Livewire::test('pages::superadmin.data')
        ->call('chooseRestore', '2026-11-03_020000')
        ->assertSeeHtml('data-test="restore-problems"')
        ->assertDontSeeHtml('data-test="start-restore"')
        ->call('startRestore')
        ->assertHasErrors('restore');
});

test('the restore backs up first, replaces database and files, signs everyone out and logs it', function () {
    restorableBackup('2026-11-01_020000');
    $database = restoreFakes();
    DB::table('sessions')->insert(['id' => 'abc', 'payload' => 'x', 'last_activity' => time()]);

    $this->artisan('siakad:restore', ['backup' => '2026-11-01_020000', '--by' => $this->superadmin->id])->assertSuccessful();

    expect($database->restored)->toBe([$this->root.DIRECTORY_SEPARATOR.'2026-11-01_020000'.DIRECTORY_SEPARATOR.'database.dump'])
        ->and(File::get($this->private.'/documents/new.txt'))->toBe('restored')
        ->and(File::exists($this->private.'/old.txt'))->toBeFalse()
        ->and(File::glob($this->private.'-before-restore-*'))->toBe([])
        ->and(DB::table('sessions')->count())->toBe(0)
        ->and(app()->isDownForMaintenance())->toBeFalse();

    $state = Restores::state();
    expect($state['status'])->toBe('done')
        ->and($state['safety_backup'])->not->toBeNull()
        ->and(Backups::find($state['safety_backup'])['kind'])->toBe(Backups::MANUAL)
        ->and(AuditLog::query()->where('event', 'backup.restored')->sole()->new_values['safety_backup'])->toBe($state['safety_backup']);
});

test('a failed restore leaves the files, brings SIAKAD back and names the backup from before', function () {
    restorableBackup('2026-11-01_020000');
    restoreFakes(fn () => throw new RuntimeException('pg_restore failed: disk full'));

    $this->artisan('siakad:restore', ['backup' => '2026-11-01_020000', '--by' => $this->superadmin->id])->assertFailed();

    $state = Restores::state();
    expect($state['status'])->toBe('failed')
        ->and($state['message'])->toContain('disk full')
        ->and($state['safety_backup'])->not->toBeNull()
        ->and(File::get($this->private.'/old.txt'))->toBe('before')
        ->and(app()->isDownForMaintenance())->toBeFalse();
});

test('a backup from a newer SIAKAD is refused before anything changes', function () {
    restorableBackup('2026-11-03_020000', ['last_migration' => '2099_01_01_000000_from_the_future']);
    $database = restoreFakes();

    $this->artisan('siakad:restore', ['backup' => '2026-11-03_020000'])->assertFailed();

    expect($database->restored)->toBe([])
        ->and(Backups::all()->count())->toBe(1);
});
