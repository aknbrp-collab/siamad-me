<?php

use App\Actions\Academic\ManageClassGroups;
use App\Actions\Academic\SemesterLifecycle;
use App\Actions\Enrollment\ManageClassEnrollments;
use App\Actions\Enrollment\ManageCourseOfferings;
use App\Actions\Finance\CopyFeeRates;
use App\Enums\Gender;
use App\Enums\Role;
use App\Enums\SemesterTerm;
use App\Models\AcademicYear;
use App\Models\ClassEnrollment;
use App\Models\ClassGroup;
use App\Models\FeeRate;
use App\Models\FeeType;
use App\Models\Semester;
use App\Models\Student;
use App\Models\StudyProgram;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->program = StudyProgram::factory()->create();
    $y1 = AcademicYear::factory()->create(['name' => '2026/2027', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);
    $y2 = AcademicYear::factory()->create(['name' => '2027/2028', 'starts_on' => '2027-07-01', 'ends_on' => '2028-06-30']);
    $this->years = [$y1, $y2];
    $this->odd = Semester::factory()->active()->create(['academic_year_id' => $y1->id, 'term' => SemesterTerm::Odd]);
    $this->even = Semester::factory()->create(['academic_year_id' => $y1->id, 'term' => SemesterTerm::Even]);
    $this->nextOdd = Semester::factory()->create(['academic_year_id' => $y2->id, 'term' => SemesterTerm::Odd]);

    $this->classes = app(ManageClassGroups::class);
    $this->putraA = $this->classes->save(null, [
        'study_program_id' => $this->program->id, 'gender' => Gender::Male->value,
        'label' => 'Putra A', 'entry_semester_id' => $this->odd->id, 'start_level' => 1,
    ]);
    $this->ahmad = Student::factory()->create(['study_program_id' => $this->program->id]);
});

test('a class moves up one level every semester, across academic years', function () {
    $class = $this->putraA->fresh();

    expect($class->name)->toBe('Semester I Putra A')
        ->and($class->levelIn($this->even))->toBe(2)
        ->and($class->nameIn($this->nextOdd))->toBe('Semester III Putra A')
        ->and($class->usableIn($this->nextOdd))->toBeTrue();

    // Activating the next semester renames it; earlier placements keep their name and level.
    app(ManageClassEnrollments::class)->enroll($this->odd, $class, [$this->ahmad->id]);
    $lifecycle = app(SemesterLifecycle::class);
    $lifecycle->close($this->odd, confirmed: true);
    $lifecycle->activate($this->even);

    expect($class->fresh())->name->toBe('Semester II Putra A')->level->toBe(2)
        ->and(ClassEnrollment::query()->sole())->class_name->toBe('Semester I Putra A')->level->toBe(1);
});

test('classes made before v4 keep their level and end with their last semester', function () {
    $old = ClassGroup::factory()->for($this->program)->create(['name' => 'Semester II Putra', 'level' => 2, 'gender' => Gender::Male, 'last_semester_id' => $this->odd->id]);

    expect($old->nameIn($this->even))->toBe('Semester II Putra')
        ->and($old->usableIn($this->odd))->toBeTrue()
        ->and($old->usableIn($this->even))->toBeFalse()
        ->and(fn () => app(ManageClassEnrollments::class)->enroll($this->even, $old, [$this->ahmad->id]))->toThrow(ValidationException::class, 'not in use');

    app(SemesterLifecycle::class)->close($this->odd, confirmed: true);
    app(SemesterLifecycle::class)->activate($this->even);
    expect($old->fresh()->name)->toBe('Semester II Putra');
});

test('carrying placements forward keeps a class with its mahasantri, one level up', function () {
    app(ManageClassEnrollments::class)->enroll($this->odd, $this->putraA, [$this->ahmad->id]);

    $this->actingAs(User::factory()->withRole(Role::Admin)->create());
    Livewire::test('pages::admin.semesters.show', ['semester' => $this->even])
        ->call('openCopy')
        ->set('copyFromId', $this->odd->id)
        ->assertSet('classMap', [$this->putraA->id => $this->putraA->id])
        ->assertSee('Semester II Putra A')
        ->call('copyEnrollments')
        ->assertHasNoErrors();

    expect(ClassEnrollment::query()->where('semester_id', $this->even->id)->sole())
        ->class_group_id->toBe($this->putraA->id)
        ->class_name->toBe('Semester II Putra A')
        ->level->toBe(2);
});

test('offerings keep the class name of their semester; copying skips classes that moved up', function () {
    $offerings = app(ManageCourseOfferings::class);
    $teacher = Teacher::factory()->create();
    $offering = $offerings->save($this->odd, null, ['subject_id' => Subject::factory()->create()->id, 'class_group_id' => $this->putraA->id, 'teacher_id' => $teacher->id]);

    expect($offering->class_name)->toBe('Semester I Putra A')
        ->and($offerings->copy($this->odd, $this->even))->toBe(['copied' => 0, 'skipped' => 1, 'clashes' => 0]);

    // A class that starts later cannot have offerings before it starts.
    $later = $this->classes->save(null, ['study_program_id' => $this->program->id, 'gender' => 'female', 'label' => 'Putri A', 'entry_semester_id' => $this->nextOdd->id, 'start_level' => 1]);
    expect(fn () => $offerings->save($this->odd, null, ['subject_id' => Subject::factory()->create()->id, 'class_group_id' => $later->id, 'teacher_id' => $teacher->id]))
        ->toThrow(ValidationException::class, 'not in use');
});

test('a class rate is copied to the class at the same level next year', function () {
    // The next intake starts at Semester I in 2027/2028; Putra A is then Semester III.
    $intake = $this->classes->save(null, ['study_program_id' => $this->program->id, 'gender' => 'male', 'label' => 'Putra B', 'entry_semester_id' => $this->nextOdd->id, 'start_level' => 1]);
    $putri = $this->classes->save(null, ['study_program_id' => $this->program->id, 'gender' => 'female', 'label' => 'Putri A', 'entry_semester_id' => $this->odd->id, 'start_level' => 1]);
    $fee = FeeType::factory()->create();
    FeeRate::create(['fee_type_id' => $fee->id, 'academic_year_id' => $this->years[0]->id, 'class_group_id' => $this->putraA->id, 'amount' => 3_000_000]);
    FeeRate::create(['fee_type_id' => $fee->id, 'academic_year_id' => $this->years[0]->id, 'class_group_id' => $putri->id, 'amount' => 2_500_000]);

    // No putri class starts at Semester I next year: that rate is skipped.
    expect(app(CopyFeeRates::class)->handle($this->years[0], $this->years[1]))->toBe(['copied' => 1, 'skipped' => 1])
        ->and(FeeRate::query()->where('academic_year_id', $this->years[1]->id)->sole()->class_group_id)->toBe($intake->id);
});

test('the Classes page creates a class that starts in a semester at a level', function () {
    $this->actingAs(User::factory()->withRole(Role::Admin)->create())->get(route('admin.class-groups'))
        ->assertOk()->assertSee('Semester I Putra A');

    Livewire::test('pages::admin.class-groups')
        ->call('create')
        ->assertSet('entrySemesterId', $this->even->id)
        ->set('label', 'Putri A')
        ->set('gender', 'female')
        ->set('startLevel', 2)
        ->call('save')
        ->assertHasNoErrors()
        // The same name, start and level twice is refused.
        ->call('create')
        ->set('label', 'Putri A')
        ->set('gender', 'female')
        ->set('startLevel', 2)
        ->call('save')
        ->assertHasErrors('label');

    expect(ClassGroup::query()->where('label', 'Putri A')->sole())
        ->name->toBe('Semester II Putri A')
        ->entry_semester_id->toBe($this->even->id);
});
