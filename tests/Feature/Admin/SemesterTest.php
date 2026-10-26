<?php

use App\Actions\Enrollment\ManageClassEnrollments;
use App\Actions\Enrollment\ManageCourseOfferings;
use App\Enums\Role;
use App\Enums\SemesterStatus;
use App\Enums\StudentStatus;
use App\Models\AcademicYear;
use App\Models\ClassEnrollment;
use App\Models\ClassGroup;
use App\Models\CourseEnrollment;
use App\Models\CourseOffering;
use App\Models\Semester;
use App\Models\Student;
use App\Models\StudyProgram;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Database\QueryException;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->withRole(Role::Admin)->create());
});

test('creating an academic year creates its two planned semesters', function () {
    $this->get(route('admin.academic-years'))->assertOk();

    Livewire::test('pages::admin.academic-years')
        ->call('create')
        ->set('name', '2026/2027')
        ->call('save')
        ->assertHasNoErrors();

    $year = AcademicYear::query()->where('name', '2026/2027')->firstOrFail();

    expect($year->semesters)->toHaveCount(2)
        ->and($year->semesters->pluck('status')->unique()->all())->toBe([SemesterStatus::Planned]);
});

test('academic year names must be consecutive years', function () {
    Livewire::test('pages::admin.academic-years')
        ->call('create')
        ->set('name', '2026/2028')
        ->call('save')
        ->assertHasErrors('name');
});

test('only one semester can be active', function () {
    $active = Semester::factory()->active()->create();
    $planned = Semester::factory()->create();

    Livewire::test('pages::admin.academic-years')
        ->call('activate', $planned->id)
        ->assertHasErrors('semester');

    Livewire::test('pages::admin.academic-years')
        ->call('confirmClose', $active->id)
        ->call('close')
        ->assertHasNoErrors();

    Livewire::test('pages::admin.academic-years')
        ->call('activate', $planned->id)
        ->assertHasNoErrors();

    expect($active->fresh()->status)->toBe(SemesterStatus::Closed)
        ->and($planned->fresh()->status)->toBe(SemesterStatus::Active);
});

test('the database itself refuses a second active semester', function () {
    Semester::factory()->active()->create();

    expect(fn () => Semester::factory()->active()->create())->toThrow(QueryException::class);
});

test('only superadmin can reopen a closed semester, with a reason', function () {
    $closed = Semester::factory()->closed()->create();

    Livewire::test('pages::admin.academic-years')
        ->call('confirmReopen', $closed->id)
        ->assertForbidden();

    $this->actingAs($superadmin = User::factory()->withRole(Role::Superadmin)->create());
    $this->get(route('superadmin.dashboard'));

    Livewire::test('pages::admin.academic-years')
        ->call('confirmReopen', $closed->id)
        ->call('reopen')
        ->assertHasErrors('reopenReason')
        ->set('reopenReason', 'Koreksi nilai Fiqh 1')
        ->call('reopen')
        ->assertHasNoErrors();

    expect($closed->fresh())
        ->status->toBe(SemesterStatus::Active)
        ->status_note->toBe('Koreksi nilai Fiqh 1');
});

function semesterSetup(): array
{
    $program = StudyProgram::factory()->create();
    $semester = Semester::factory()->active()->create();
    $classA = ClassGroup::factory()->for($program)->create(['name' => 'Marhalah 1 A', 'level' => 1]);
    $classB = ClassGroup::factory()->for($program)->create(['name' => 'Marhalah 2 A', 'level' => 2]);
    $subject = Subject::factory()->for($program)->create(['code' => 'FQH1', 'name' => 'Fiqh 1', 'credits' => 3]);
    $teacher = Teacher::factory()->create();

    return compact('program', 'semester', 'classA', 'classB', 'subject', 'teacher');
}

test('placing students in a class enrols them in the class offerings', function () {
    ['semester' => $semester, 'classA' => $classA, 'subject' => $subject, 'teacher' => $teacher] = semesterSetup();
    $student = Student::factory()->create();

    $this->get(route('admin.semesters.show', $semester))->assertOk();

    $component = Livewire::test('pages::admin.semesters.show', ['semester' => $semester])
        ->set('tab', 'offerings')
        ->call('createOffering')
        ->set('subject_id', $subject->id)
        ->set('class_group_id', $classA->id)
        ->set('teacher_id', $teacher->id)
        ->set('day_of_week', 1)
        ->set('starts_at', '07:30')
        ->set('ends_at', '09:00')
        ->call('saveOffering')
        ->assertHasNoErrors();

    $offering = CourseOffering::query()->sole();
    expect($offering)->subject_name->toBe('Fiqh 1')->credits->toBe(3);

    $component->set('tab', 'classes')
        ->call('selectClass', $classA->id)
        ->set('studentSearch', $student->nim)
        ->assertSee($student->user->name)
        ->call('enroll', $student->id)
        ->assertHasNoErrors();

    expect(ClassEnrollment::query()->sole())->class_name->toBe('Marhalah 1 A')
        ->and($offering->enrollments()->pluck('student_id')->all())->toBe([$student->id]);
});

test('subject snapshots survive later subject edits', function () {
    ['semester' => $semester, 'classA' => $classA, 'subject' => $subject, 'teacher' => $teacher] = semesterSetup();

    $offering = app(ManageCourseOfferings::class)->save($semester, null, [
        'subject_id' => $subject->id, 'class_group_id' => $classA->id, 'teacher_id' => $teacher->id,
    ]);

    $subject->update(['name' => 'Fiqh Ibadah', 'credits' => 2]);

    expect($offering->fresh())->subject_name->toBe('Fiqh 1')->credits->toBe(3);
});

test('moving a student to another class re-syncs both classes offerings', function () {
    ['semester' => $semester, 'classA' => $classA, 'classB' => $classB, 'subject' => $subject, 'teacher' => $teacher, 'program' => $program] = semesterSetup();
    $manage = app(ManageCourseOfferings::class);
    $offeringA = $manage->save($semester, null, ['subject_id' => $subject->id, 'class_group_id' => $classA->id, 'teacher_id' => $teacher->id]);
    $other = Subject::factory()->for($program)->create();
    $offeringB = $manage->save($semester, null, ['subject_id' => $other->id, 'class_group_id' => $classB->id, 'teacher_id' => $teacher->id]);

    $student = Student::factory()->create();
    $enrollments = app(ManageClassEnrollments::class);

    $enrollments->enroll($semester, $classA, [$student->id]);
    $enrollments->enroll($semester, $classB, [$student->id]);

    expect(ClassEnrollment::query()->sole()->class_group_id)->toBe($classB->id)
        ->and($offeringA->enrollments()->count())->toBe(0)
        ->and($offeringB->enrollments()->count())->toBe(1);
});

test('students with grades cannot be removed from their class', function () {
    ['semester' => $semester, 'classA' => $classA, 'subject' => $subject, 'teacher' => $teacher] = semesterSetup();
    $offering = app(ManageCourseOfferings::class)->save($semester, null, ['subject_id' => $subject->id, 'class_group_id' => $classA->id, 'teacher_id' => $teacher->id]);
    $student = Student::factory()->create();
    app(ManageClassEnrollments::class)->enroll($semester, $classA, [$student->id]);
    CourseEnrollment::query()->update(['final_score' => 85]);

    Livewire::test('pages::admin.semesters.show', ['semester' => $semester])
        ->call('selectClass', $classA->id)
        ->call('removeEnrollment', ClassEnrollment::query()->sole()->id)
        ->assertHasErrors('enrollment');

    expect(ClassEnrollment::query()->count())->toBe(1);
});

test('copying classes promotes active students and skips the rest', function () {
    ['program' => $program, 'classA' => $classA, 'classB' => $classB] = semesterSetup();
    // Fixed years: promotion only applies when copying from an earlier semester.
    $previous = Semester::factory()->closed()->even()->create(['academic_year_id' => AcademicYear::factory()->create(['name' => '2025/2026'])]);
    $next = Semester::factory()->create(['academic_year_id' => AcademicYear::factory()->create(['name' => '2026/2027'])]);

    $staying = Student::factory()->create();
    $graduated = Student::factory()->create(['status' => StudentStatus::Graduated, 'graduated_at' => '2026-06-01']);

    foreach ([$staying, $graduated] as $student) {
        ClassEnrollment::create(['semester_id' => $previous->id, 'class_group_id' => $classA->id, 'student_id' => $student->id, 'class_name' => $classA->name]);
    }

    Livewire::test('pages::admin.semesters.show', ['semester' => $next])
        ->call('openCopy')
        ->set('copyFromId', $previous->id)
        ->assertSet("classMap.{$classA->id}", $classB->id)
        ->call('copyEnrollments')
        ->assertHasNoErrors();

    expect(ClassEnrollment::query()->where('semester_id', $next->id)->pluck('student_id', 'class_group_id')->all())
        ->toBe([$classB->id => $staying->id]);
});

test('closed semesters are read-only', function () {
    ['classA' => $classA, 'subject' => $subject, 'teacher' => $teacher] = semesterSetup();
    $closed = Semester::factory()->closed()->even()->create();
    $student = Student::factory()->create();

    $this->get(route('admin.semesters.show', $closed))->assertOk()->assertSee(__('Archive — read only'));

    Livewire::test('pages::admin.semesters.show', ['semester' => $closed])
        ->call('selectClass', $classA->id)
        ->call('enroll', $student->id)
        ->assertHasErrors('semester');

    Livewire::test('pages::admin.semesters.show', ['semester' => $closed])
        ->set('subject_id', $subject->id)
        ->set('class_group_id', $classA->id)
        ->set('teacher_id', $teacher->id)
        ->call('saveOffering')
        ->assertHasErrors('semester');

    expect(ClassEnrollment::query()->count())->toBe(0)
        ->and(CourseOffering::query()->count())->toBe(0);
});

test('copying offerings snapshots the current subject and skips duplicates', function () {
    ['semester' => $semester, 'classA' => $classA, 'subject' => $subject, 'teacher' => $teacher] = semesterSetup();
    app(ManageCourseOfferings::class)->save($semester, null, ['subject_id' => $subject->id, 'class_group_id' => $classA->id, 'teacher_id' => $teacher->id, 'day_of_week' => 2]);
    $next = Semester::factory()->create();

    $copy = fn () => app(ManageCourseOfferings::class)->copy($semester, $next);

    expect($copy())->toBe(['copied' => 1, 'skipped' => 0])
        ->and($copy())->toBe(['copied' => 0, 'skipped' => 1])
        ->and(CourseOffering::query()->where('semester_id', $next->id)->sole()->day_of_week)->toBe(2);
});
