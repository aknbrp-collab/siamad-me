<?php

use App\Actions\Enrollment\ManageClassEnrollments;
use App\Actions\Enrollment\ManageCourseOfferings;
use App\Actions\Teaching\ManageGrades;
use App\Actions\Teaching\ManageMeetings;
use App\Enums\Role;
use App\Models\Attendance;
use App\Models\ClassGroup;
use App\Models\CourseEnrollment;
use App\Models\GradeScale;
use App\Models\Guardian;
use App\Models\Semester;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Support\ActiveRole;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Spatie\SimpleExcel\SimpleExcelWriter;

beforeEach(function () {
    $this->semester = Semester::factory()->active()->create();
    $this->classGroup = ClassGroup::factory()->create(['name' => 'Marhalah 1 A']);
    $this->teacher = Teacher::factory()->create();
    $subject = Subject::factory()->create(['code' => 'FQH1', 'name' => 'Fiqh Ibadah', 'credits' => 2]);
    $this->offering = app(ManageCourseOfferings::class)->save($this->semester, null, [
        'subject_id' => $subject->id, 'class_group_id' => $this->classGroup->id, 'teacher_id' => $this->teacher->id,
    ]);
    $this->student = Student::factory()->create(['nim' => '2026110001']);
    app(ManageClassEnrollments::class)->enroll($this->semester, $this->classGroup, [$this->student->id]);
    $this->enrollment = CourseEnrollment::query()->sole();
});

function signIn(User $user, ?Role $role = null): void
{
    test()->actingAs($user);
    ActiveRole::set($role ?? $user->roleList()[0]);
}

test('the ustadz runs a class: meeting, attendance, components, scores, finalise', function () {
    signIn($this->teacher->user);

    $this->get(route('ustadz.dashboard'))->assertOk()->assertSee('Fiqh Ibadah');
    $this->get(route('ustadz.offerings.meetings', $this->offering))->assertOk();

    Livewire::test('pages::ustadz.offerings.meetings', ['offering' => $this->offering])
        ->call('createMeeting')
        ->set('topic', 'Thaharah')
        ->call('saveMeeting')
        ->assertHasNoErrors()
        ->call('takeAttendance', $this->offering->sessions()->sole()->id)
        ->assertSet("statuses.{$this->enrollment->id}", 'hadir')
        ->set("statuses.{$this->enrollment->id}", 'sakit')
        ->call('saveAttendance')
        ->assertHasNoErrors();

    expect(Attendance::query()->sole()->status->value)->toBe('sakit');

    $this->get(route('ustadz.offerings.grades', $this->offering))->assertOk();

    $grades = Livewire::test('pages::ustadz.offerings.grades', ['offering' => $this->offering])
        ->assertSet('components.2.name', 'UAS')
        ->set('components.2.weight', 40)
        ->call('saveComponents')
        ->assertHasErrors('components')
        ->set('components.2.weight', 50)
        ->call('saveComponents')
        ->assertHasNoErrors();

    $components = $this->offering->components()->get();
    $grades->set("scores.{$this->enrollment->id}.{$components[0]->id}", '90')
        ->set("scores.{$this->enrollment->id}.{$components[1]->id}", '80')
        ->set("scores.{$this->enrollment->id}.{$components[2]->id}", '70')
        ->call('finalize')
        ->assertHasNoErrors();

    // 0.2·90 + 0.3·80 + 0.5·70 = 77 → B
    expect($this->enrollment->fresh())->final_score->toBe('77.00')->final_letter->toBe('B');
});

test('an ustadz cannot open another ustadz\'s offering', function () {
    $other = Teacher::factory()->create();
    signIn($other->user);

    $this->get(route('ustadz.offerings.grades', $this->offering))->assertForbidden();
    $this->get(route('ustadz.offerings.meetings', $this->offering))->assertForbidden();
    $this->get(route('ustadz.offerings.grades.template', $this->offering))->assertForbidden();
});

test('scores can be imported from the Excel template', function () {
    app(ManageGrades::class)->saveComponents($this->offering, [['name' => 'UTS', 'weight' => 40], ['name' => 'UAS', 'weight' => 60]]);
    signIn($this->teacher->user);

    $this->get(route('ustadz.offerings.grades.template', $this->offering))->assertOk()->assertDownload();

    $path = tempnam(sys_get_temp_dir(), 'scores').'.xlsx';
    SimpleExcelWriter::create($path)->addRows([
        ['nim' => '2026110001', 'name' => 'x', 'UTS' => '85', 'UAS' => '90'],
        ['nim' => '9999', 'name' => 'Stranger', 'UTS' => '50', 'UAS' => ''],
    ])->close();

    Livewire::test('pages::ustadz.offerings.grades', ['offering' => $this->offering])
        ->set('file', UploadedFile::fake()->createWithContent('scores.xlsx', file_get_contents($path)))
        ->call('checkImport')
        ->assertHasNoErrors()
        ->assertSet('importPreview.0.status', 'new')
        ->assertSet('importPreview.1.status', 'error')
        ->call('applyImport')
        ->assertHasNoErrors();

    expect($this->enrollment->scores()->pluck('score')->sort()->values()->all())->toBe(['85.00', '90.00']);
});

test('mahasantri see their KHS, transcript and PDFs; wali see their child', function () {
    $grades = app(ManageGrades::class);
    $grades->saveComponents($this->offering, [['name' => 'UAS', 'weight' => 100]]);
    $grades->saveScores($this->offering, [$this->enrollment->id => [$this->offering->components()->sole()->id => 88]]);
    $grades->finalize($this->offering, $this->teacher->user);

    signIn($this->student->user);
    $this->get(route('mahasantri.khs'))->assertOk()->assertSee('Fiqh Ibadah')->assertSee('4.00');
    $this->get(route('mahasantri.transcript'))->assertOk()->assertSee('4.00');
    $this->get(route('records.khs', [$this->student, $this->semester]))->assertOk()->assertHeader('content-type', 'application/pdf');
    $this->get(route('records.transcript', $this->student))->assertOk();

    $guardian = Guardian::factory()->create();
    $guardian->students()->attach($this->student);
    signIn($guardian->user);
    $this->get(route('wali.academic'))->assertOk()->assertSee($this->student->user->name)->assertSee('Fiqh Ibadah');

    $stranger = Student::factory()->create();
    signIn($stranger->user);
    $this->get(route('records.transcript', $this->student))->assertForbidden();
});

test('the monitor sees attendance below the minimum; only admin unlocks grades', function () {
    $grades = app(ManageGrades::class);
    $session = app(ManageMeetings::class)->save($this->offering, null, ['held_on' => '2026-08-01', 'mode' => 'offline']);
    app(ManageMeetings::class)->recordAttendance($session, [$this->enrollment->id => 'alpa']);
    $grades->saveComponents($this->offering, [['name' => 'UAS', 'weight' => 100]]);
    $grades->saveScores($this->offering, [$this->enrollment->id => [$this->offering->components()->sole()->id => 60]]);
    $grades->finalize($this->offering, $this->teacher->user);

    signIn(User::factory()->withRole(Role::AcademicMonitor)->create());
    $this->get(route('monitor.dashboard'))->assertOk()->assertSee($this->student->user->name)->assertSee('0%');
    Livewire::test('pages::monitor.dashboard')->call('openUnlock', $this->offering->id)->assertForbidden();

    signIn(User::factory()->withRole(Role::Admin)->create());
    $this->get(route('monitor.dashboard'))->assertOk();
    Livewire::test('pages::monitor.dashboard')
        ->call('openUnlock', $this->offering->id)
        ->set('unlockReason', 'Koreksi UAS')
        ->call('unlock')
        ->assertHasNoErrors();

    expect($this->offering->fresh()->isFinalized())->toBeFalse();
});

test('admin edits the grade scale and sees a student record', function () {
    signIn(User::factory()->withRole(Role::Admin)->create());

    $this->get(route('admin.grade-scale'))->assertOk();
    Livewire::test('pages::admin.grade-scale')
        ->set('bands.0.point', 3.9)
        ->call('save')
        ->assertHasNoErrors();
    expect(GradeScale::query()->where('letter', 'A')->value('point'))->toBe('3.90');

    Livewire::test('pages::admin.grade-scale')
        ->set('bands.6.min_score', 5)
        ->call('save')
        ->assertHasErrors('bands');

    $this->get(route('admin.students.record', $this->student))->assertOk()->assertSee($this->student->nim);
});
