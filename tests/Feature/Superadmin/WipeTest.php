<?php

use App\Actions\Superadmin\WipeData;
use App\Console\Commands\WipeCommand;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Student;
use App\Models\User;
use App\Support\BackgroundCommand;
use App\Support\Backups;
use App\Support\DataGroups;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * data-manage D52: deleting data by groups, with what they need, keeping the Superadmin and settings.
 */

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/siakad-wipe-test-'.uniqid();
    config(['siakad.backup.path' => $this->root, 'siakad.backup.connection' => 'pgsql']);
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

function wipeSuperadmin(): User
{
    return User::query()->whereHas('roles', fn ($query) => $query->where('role', Role::Superadmin))->firstOrFail();
}

test('every table is in one group or kept, and each group asks for the groups that point at it', function () {
    $grouped = collect(DataGroups::TABLES)->flatMap(fn (array $tables, string $group) => collect($tables)->mapWithKeys(fn (string $table) => [$table => $group]));

    expect($grouped->count())->toBe(collect(DataGroups::TABLES)->flatten()->unique()->count())
        ->and(array_values(array_diff(Schema::getTableListing(schemaQualified: false), [...$grouped->keys()->all(), ...DataGroups::KEPT])))->toBe([])
        ->and(array_values(array_intersect($grouped->keys()->all(), DataGroups::KEPT)))->toBe([]);

    // A row in group A pointing (restrict) at a row in group B: deleting B must delete A too.
    foreach ($grouped as $table => $group) {
        foreach (Schema::getForeignKeys($table) as $key) {
            $target = $grouped[$key['foreign_table']] ?? null;
            if ($target !== null && $target !== $group && ! in_array($key['on_delete'], ['cascade', 'set null'], true)) {
                expect(DataGroups::withNeeds([$target]))->toContain($group);
            }
        }
    }
});

test('each group can be deleted on its own from the demo data, leaving everything else', function (string $group) {
    $this->seed(DatabaseSeeder::class);
    $actor = wipeSuperadmin();
    $chosen = DataGroups::withNeeds([$group]);
    $deleted = collect($chosen)->flatMap(fn (string $name) => DataGroups::TABLES[$name])->all();
    $kept = array_values(array_diff(collect(DataGroups::TABLES)->flatten()->all(), $deleted, ['audit_logs', 'notifications']));
    $before = collect($kept)->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()]);
    $settings = DB::table('settings')->count();
    $accounts = DataGroups::users($chosen);

    $counts = app(WipeData::class)->handle([$group], $actor);

    expect(array_keys($counts))->toBe($chosen);
    foreach ($deleted as $table) {
        // The audit log keeps one entry: this deletion.
        expect(DB::table($table)->count())->toBe($table === 'audit_logs' ? 1 : 0, "{$table} should be empty");
    }
    foreach ($kept as $table) {
        expect(DB::table($table)->count())->toBe($before[$table], "{$table} should be untouched");
    }
    expect(User::query()->whereKey($accounts)->count())->toBe(0)
        ->and(User::query()->whereKey($actor->id)->exists())->toBeTrue()
        ->and(DB::table('settings')->count())->toBe($settings)
        ->and(AuditLog::query()->where('event', 'data.deleted')->sole()->new_values['groups'])->toBe($chosen);
})->with(array_keys(DataGroups::TABLES));

test('deleting staff accounts keeps what they recorded, under the Superadmin', function () {
    $this->seed(DatabaseSeeder::class);
    $actor = wipeSuperadmin();
    $staff = User::query()->where('username', 'keuangan')->sole();
    $recorded = DB::table('payments')->where('recorded_by', $staff->id)->count();
    $payments = DB::table('payments')->count();

    app(WipeData::class)->handle([DataGroups::STAFF], $actor);

    expect(User::query()->whereKey($staff->id)->exists())->toBeFalse()
        ->and(User::query()->where('username', 'admin')->exists())->toBeFalse()
        ->and(DB::table('payments')->count())->toBe($payments)
        ->and(DB::table('payments')->where('recorded_by', $actor->id)->count())->toBeGreaterThanOrEqual($recorded)
        ->and(Student::query()->count())->toBeGreaterThan(0);
});

test('the files of deleted rows are removed, other files stay', function () {
    Storage::fake('local');
    $this->seed(DatabaseSeeder::class);
    $student = Student::query()->first();
    $student->update(['photo_path' => 'photos/ali.jpg']);
    Storage::disk('local')->put('photos/ali.jpg', 'photo');
    Storage::disk('local')->put('branding/logo.png', 'logo');

    app(WipeData::class)->handle([DataGroups::PEOPLE], wipeSuperadmin());

    expect(Storage::disk('local')->exists('photos/ali.jpg'))->toBeFalse()
        ->and(Storage::disk('local')->exists('branding/logo.png'))->toBeTrue();
});

test('siakad:wipe backs up first and stops when the backup fails', function () {
    $this->seed(DatabaseSeeder::class);
    $actor = wipeSuperadmin();
    $students = Student::query()->count();

    Process::fake(fn () => Process::result(errorOutput: 'connection refused', exitCode: 1));
    $this->artisan('siakad:wipe', ['groups' => ['orang'], '--by' => $actor->id])->assertFailed();
    expect(Student::query()->count())->toBe($students)
        ->and(WipeCommand::state()['status'])->toBe('failed');

    Process::fake(function (PendingProcess $process) {
        $file = collect($process->command)->first(fn (string $arg) => str_starts_with($arg, '--file='));
        File::put(substr($file, 7), 'dump');

        return Process::result();
    });
    $this->artisan('siakad:wipe', ['groups' => ['orang'], '--by' => $actor->id])->assertSuccessful();

    $state = WipeCommand::state();
    expect(Student::query()->count())->toBe(0)
        ->and($state['status'])->toBe('done')
        ->and(Backups::find($state['safety_backup'])['kind'])->toBe(Backups::MANUAL)
        ->and($state['groups'])->toBe(DataGroups::withNeeds(['orang']));

    $this->artisan('siakad:wipe', ['groups' => ['everything'], '--by' => $actor->id])->assertFailed();
    $this->artisan('siakad:wipe', ['groups' => ['log'], '--by' => User::factory()->withRole(Role::Admin)->create()->id])->assertFailed();
});

test('the page ticks what a group needs and asks for the password and phrase', function () {
    $actor = User::factory()->withRole(Role::Superadmin)->create();
    $started = [];
    app()->instance(BackgroundCommand::class, new class($started) extends BackgroundCommand
    {
        public function __construct(public array &$started) {}

        public function start(string $command, array $arguments = []): void
        {
            $this->started[] = [$command, $arguments];
        }
    });
    $this->actingAs($actor);

    Livewire::test('pages::superadmin.data')
        ->call('confirmWipe')->assertHasErrors('wipeGroups')
        ->set('wipeGroups', ['orang'])
        ->assertSet('wipeSelection', ['akademik', 'keuangan', 'surat', 'artikel', 'orang'])
        ->assertSeeHtml('data-test="wipe-summary"')
        ->call('confirmWipe')
        ->set('wipePassword', 'wrong')->set('wipePhrase', 'HAPUS DATA')
        ->call('startWipe')->assertHasErrors('wipePassword')
        ->set('wipePassword', 'password')->set('wipePhrase', 'hapus')
        ->call('startWipe')->assertHasErrors('wipePhrase')
        ->set('wipePhrase', 'HAPUS DATA')
        ->call('startWipe')->assertHasNoErrors()
        ->assertSeeHtml('data-test="wipe-running"');

    expect($started)->toBe([['siakad:wipe', ['akademik', 'keuangan', 'surat', 'artikel', 'orang', '--by='.$actor->id]]]);
});
