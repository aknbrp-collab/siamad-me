<?php

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\BackgroundCommand;
use App\Support\Backups;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

/*
 * data-manage D50: the Superadmin's Data page lists backups, makes one by hand, downloads and deletes them.
 */

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/siakad-data-test-'.uniqid();
    $this->private = sys_get_temp_dir().'/siakad-data-private-'.uniqid();
    File::ensureDirectoryExists($this->private);
    File::put($this->private.'/photo.jpg', 'photo');
    config(['siakad.backup.path' => $this->root, 'filesystems.disks.local.root' => $this->private, 'siakad.backup.connection' => 'pgsql']);

    $this->superadmin = User::factory()->withRole(Role::Superadmin)->create(['name' => 'Pak Super']);
});

afterEach(function () {
    File::deleteDirectory($this->root);
    File::deleteDirectory($this->private);
});

/**
 * A finished backup folder with its manifest.
 */
function dataBackup(string $name, string $kind, ?User $by = null, ?int $age = null): string
{
    $folder = config('siakad.backup.path').DIRECTORY_SEPARATOR.$name;
    File::ensureDirectoryExists($folder);
    File::put($folder.'/database.dump', 'dump');
    File::put($folder.'/files.zip', 'zip');
    Backups::writeManifest($folder, $kind, $by, 1);

    if ($age !== null) {
        touch($folder, now()->subDays($age)->getTimestamp());
    }

    return $folder;
}

test('only the Superadmin opens the Data page and downloads backups', function () {
    dataBackup('2026-11-01_020000', Backups::SCHEDULED);
    $admin = User::factory()->withRole(Role::Admin)->create();

    $this->actingAs($admin)->get(route('superadmin.data'))->assertForbidden();
    $this->actingAs($admin)->get(route('superadmin.data.download', '2026-11-01_020000'))->assertForbidden();

    $this->actingAs($this->superadmin)->get(route('superadmin.data'))->assertOk()->assertSee('2026-11-01_020000');
});

test('backups are listed newest first with who made them, records and size', function () {
    dataBackup('2026-11-01_020000', Backups::SCHEDULED);
    dataBackup('2026-11-05_101500', Backups::MANUAL, $this->superadmin);

    $backups = Backups::all();
    expect($backups->pluck('name')->all())->toBe(['2026-11-05_101500', '2026-11-01_020000'])
        ->and($backups[0]['kind'])->toBe(Backups::MANUAL)
        ->and($backups[0]['made_by'])->toBe('Pak Super')
        ->and($backups[0]['manifest']['tables'])->toHaveKey('users')
        ->and($backups[0]['manifest']['files']['database.dump']['sha256'])->toBe(hash('sha256', 'dump'));

    $this->actingAs($this->superadmin);
    Livewire::test('pages::superadmin.data')
        ->assertSee('Pak Super')
        ->assertSeeHtml('data-test="delete-2026-11-05_101500"')
        ->assertDontSeeHtml('data-test="delete-2026-11-01_020000"');
});

test('making a backup runs in its own process, shows while it runs and is kept until deleted', function () {
    $started = [];
    app()->instance(BackgroundCommand::class, new class($started) extends BackgroundCommand
    {
        /** @param  list<array{0: string, 1: list<string>}>  $started */
        public function __construct(public array &$started) {}

        public function start(string $command, array $arguments = []): void
        {
            $this->started[] = [$command, $arguments];
        }
    });
    $this->actingAs($this->superadmin);

    Livewire::test('pages::superadmin.data')
        ->call('makeBackup')
        ->assertSeeHtml('data-test="backup-running"')
        ->call('makeBackup');
    expect($started)->toBe([['siakad:backup', ['--by='.$this->superadmin->id]]]);

    // What that process runs: siakad:backup by this Superadmin, which records its end for the page.
    Process::fake(function (PendingProcess $process) {
        $file = collect($process->command)->first(fn (string $arg) => str_starts_with($arg, '--file='));
        File::put(substr($file, 7), 'dump');

        return Process::result();
    });
    $this->artisan('siakad:backup', ['--by' => $this->superadmin->id])->assertSuccessful();

    $made = Backups::all()->sole();
    expect($made['kind'])->toBe(Backups::MANUAL)
        ->and($made['made_by'])->toBe('Pak Super')
        ->and(Backups::running())->toBeNull()
        ->and(Backups::last()['ok'])->toBeTrue();
});

test('a backup downloads as one zip and the download is logged', function () {
    dataBackup('2026-11-05_101500', Backups::MANUAL, $this->superadmin);

    $response = $this->actingAs($this->superadmin)->get(route('superadmin.data.download', '2026-11-05_101500'))
        ->assertOk()->assertDownload('siakad-backup-2026-11-05_101500.zip');

    $zip = new ZipArchive;
    $zip->open($response->baseResponse->getFile()->getPathname());
    expect(collect(range(0, $zip->numFiles - 1))->map(fn (int $index) => $zip->getNameIndex($index))->sort()->values()->all())
        ->toBe(['database.dump', 'files.zip', 'manifest.json']);
    $zip->close();

    expect(AuditLog::query()->where('event', 'backup.downloaded')->sole()->auditable_id)->toBe('2026-11-05_101500');

    $this->get(route('superadmin.data.download', '2026-11-30_000000'))->assertNotFound();
});

test('only backups made by hand can be deleted; the daily ones expire', function () {
    $manual = dataBackup('2026-11-05_101500', Backups::MANUAL, $this->superadmin);
    $daily = dataBackup('2026-11-01_020000', Backups::SCHEDULED);
    $this->actingAs($this->superadmin);

    Livewire::test('pages::superadmin.data')->call('deleteBackup', '2026-11-01_020000')->assertForbidden();
    Livewire::test('pages::superadmin.data')->call('deleteBackup', '2026-11-05_101500');

    expect(File::isDirectory($manual))->toBeFalse()
        ->and(File::isDirectory($daily))->toBeTrue()
        ->and(AuditLog::query()->where('event', 'backup.deleted')->count())->toBe(1);
});

test('the daily clean-up leaves backups made by hand', function () {
    $oldManual = dataBackup('2026-01-01_101500', Backups::MANUAL, $this->superadmin, 60);
    $oldDaily = dataBackup('2026-01-02_020000', Backups::SCHEDULED, null, 60);

    Process::fake(function (PendingProcess $process) {
        $file = collect($process->command)->first(fn (string $arg) => str_starts_with($arg, '--file='));
        File::put(substr($file, 7), 'dump');

        return Process::result();
    });
    $this->artisan('siakad:backup')->assertSuccessful();

    expect(File::isDirectory($oldManual))->toBeTrue()->and(File::isDirectory($oldDaily))->toBeFalse();
});

test('a backup run that died does not block the page for ever', function () {
    File::ensureDirectoryExists($this->root);
    File::put($this->root.'/.running.json', (string) json_encode(['started_at' => now()->subHours(4)->toIso8601String(), 'by' => 'Pak Super']));

    expect(Backups::running())->toBeNull();
});

test('tests cannot start real background processes by accident', function () {
    expect(fn () => (new BackgroundCommand)->start('siakad:backup'))->toThrow(LogicException::class);
});
