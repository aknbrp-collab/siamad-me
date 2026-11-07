<?php

use App\Actions\Enrollment\ManageClassEnrollments;
use App\Actions\Finance\GenerateBills;
use App\Actions\Finance\RecordPayment;
use App\Actions\Teaching\ManageMeetings;
use App\Enums\Role;
use App\Models\Bill;
use App\Models\ClassGroup;
use App\Models\CourseOffering;
use App\Models\Semester;
use App\Models\Student;
use App\Models\StudyProgram;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Pages to measure, as [route name, parameters, login ID, active role].
 *
 * @return list<array{0: string, 1: array<string, mixed>, 2: string, 3: Role}>
 */
function measuredPages(): array
{
    $fiqh = CourseOffering::query()->where('subject_code', 'FQH101')->sole();
    $nahwu = CourseOffering::query()->where('subject_code', 'NHW101')->sole();
    $semester = Semester::query()->where('status', 'active')->sole();
    $faqih = Student::query()->where('nim', '2026110001')->sole();

    return [
        ['admin.students.index', [], 'admin', Role::Admin],
        ['admin.teachers.index', [], 'admin', Role::Admin],
        ['admin.guardians.index', [], 'admin', Role::Admin],
        ['admin.documents', [], 'admin', Role::Admin],
        ['admin.payments', [], 'admin', Role::Admin],
        ['admin.semesters.show', ['semester' => $semester, 'class' => $fiqh->class_group_id], 'admin', Role::Admin],
        ['admin.students.record', ['student' => $faqih], 'admin', Role::Admin],
        ['finance.dashboard', [], 'superadmin', Role::Superadmin],
        ['finance.bills.index', [], 'keuangan', Role::AdminPayment],
        ['finance.bills.index', ['view' => 'grid'], 'keuangan', Role::AdminPayment],
        ['finance.handovers', [], 'keuangan', Role::AdminPayment],
        ['finance.scholarships', [], 'keuangan', Role::AdminPayment],
        ['monitor.dashboard', [], 'monitor', Role::AcademicMonitor],
        ['evaluations', [], 'admin', Role::Admin],
        ['mahasantri.evaluations', [], '2026110001', Role::Student],
        ['monitor.activities', [], 'monitor', Role::AcademicMonitor],
        ['superadmin.users.index', [], 'superadmin', Role::Superadmin],
        ['superadmin.audit-logs', [], 'superadmin', Role::Superadmin],
        ['ustadz.offerings.meetings', ['offering' => $fiqh], '2026010001', Role::Teacher],
        ['ustadz.offerings.grades', ['offering' => $fiqh], '2026010001', Role::Teacher],
        ['ustadz.offerings.assignments', ['offering' => $fiqh], '2026010001', Role::Teacher],
        ['ustadz.offerings.quizzes', ['offering' => $fiqh], '2026010001', Role::Teacher],
        ['ustadz.offerings.grades', ['offering' => $nahwu], '2026010002', Role::Teacher],
        ['mahasantri.khs', [], '2026110001', Role::Student],
        ['wali.academic', [], 'ws-demo0001', Role::Guardian],
        ['wali.bills', [], 'ws-demo0001', Role::Guardian],
    ];
}

function countQueries(string $route, array $parameters, string $username, Role $role): int
{
    test()->actingAs(User::query()->where('username', $username)->sole())
        ->withSession(['active_role' => $role->value]);

    $count = 0;
    DB::listen(function () use (&$count) {
        $count++;
    });

    test()->get(route($route, $parameters))->assertOk();
    DB::getEventDispatcher()?->forget('Illuminate\Database\Events\QueryExecuted');

    return $count;
}

/**
 * More mahasantri in the demo class: documents, course enrolments,
 * attendance in every Fiqh meeting, a bill each and a cash payment.
 */
function addMahasantri(int $count): void
{
    // Done by a signed-in staff member, so audit entries have a user as in real use.
    $recorder = User::query()->where('username', 'keuangan')->sole();
    test()->actingAs($recorder);

    $semester = Semester::query()->where('status', 'active')->sole();
    $class = ClassGroup::query()->where('name', 'Semester I Putra')->sole();
    $students = Student::factory()->count($count)->withDocuments()
        ->create(['study_program_id' => StudyProgram::query()->value('id')]);

    app(ManageClassEnrollments::class)->enroll($semester, $class, $students->pluck('id')->all());

    $fiqh = CourseOffering::query()->where('subject_code', 'FQH101')->sole();
    foreach ($fiqh->sessions()->get() as $session) {
        $statuses = $fiqh->enrollments()->pluck('id')->mapWithKeys(fn (int $id) => [$id => 'hadir'])->all();
        app(ManageMeetings::class)->recordAttendance($session, $statuses);
    }

    app(GenerateBills::class)->handle($semester);

    Bill::query()->whereIn('student_id', $students->pluck('id'))->get()
        ->each(fn (Bill $bill) => app(RecordPayment::class)->handle($bill, $recorder, [
            'amount' => 500000, 'method' => 'cash', 'paid_on' => now()->toDateString(),
        ]));
}

test('page queries do not grow with the number of mahasantri (no N+1)', function () {
    $this->seed(DatabaseSeeder::class);

    // Baseline with a few rows everywhere, so eager loads already run once.
    addMahasantri(2);
    $before = array_map(fn (array $page) => countQueries(...$page), measuredPages());

    addMahasantri(20);

    $growing = [];
    foreach (measuredPages() as $i => $page) {
        $after = countQueries(...$page);

        if ($after > $before[$i]) {
            $growing[] = "{$page[0]}: {$before[$i]} → {$after} queries";
        }
    }

    expect($growing)->toBe([]);
});

test('every foreign key is indexed', function () {
    // Single-column foreign keys that lead no (non-partial) index, as table.column.
    $unindexed = DB::getDriverName() === 'pgsql' ? unindexedForeignKeysOnPostgres() : unindexedForeignKeysOnSqlite();

    expect($unindexed)->toBe([]);
});

/**
 * @return list<string>
 */
function unindexedForeignKeysOnPostgres(): array
{
    return collect(DB::select(<<<'SQL'
        select c.conrelid::regclass::text || '.' || a.attname as fk
        from pg_constraint c
        join pg_attribute a on a.attrelid = c.conrelid and a.attnum = c.conkey[1]
        where c.contype = 'f'
          and array_length(c.conkey, 1) = 1
          and not exists (
              select 1 from pg_index i
              where i.indrelid = c.conrelid and i.indkey[0] = c.conkey[1] and i.indpred is null
          )
        SQL))->pluck('fk')->values()->all();
}

/**
 * The same check through SQLite's PRAGMAs: each table's foreign keys and the
 * first column of each full index (primary keys included).
 *
 * @return list<string>
 */
function unindexedForeignKeysOnSqlite(): array
{
    $unindexed = [];
    $tables = collect(DB::select("select name from sqlite_master where type = 'table' and name not like 'sqlite_%'"))->pluck('name');

    foreach ($tables as $table) {
        $keys = collect(DB::select("pragma foreign_key_list('{$table}')"))->groupBy('id')->filter(fn ($columns) => $columns->count() === 1);

        if ($keys->isEmpty()) {
            continue;
        }

        $leading = collect(DB::select("pragma index_list('{$table}')"))
            ->filter(fn ($index) => (int) $index->partial === 0)
            ->map(fn ($index) => collect(DB::select("pragma index_info('{$index->name}')"))->firstWhere('seqno', 0)?->name)
            ->filter()->all();

        foreach ($keys as $columns) {
            if (! in_array($columns->first()->from, $leading, true)) {
                $unindexed[] = $table.'.'.$columns->first()->from;
            }
        }
    }

    sort($unindexed);

    return $unindexed;
}
