<?php

use App\Actions\Enrollment\ManageClassEnrollments;
use App\Enums\Gender;
use App\Enums\Role;
use App\Enums\SemesterTerm;
use App\Models\AcademicYear;
use App\Models\ClassEnrollment;
use App\Models\ClassGroup;
use App\Models\Semester;
use App\Models\Student;
use App\Models\StudyProgram;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->program = StudyProgram::factory()->create();
    $year = AcademicYear::factory()->create(['name' => '1992/1993']);
    $this->odd = Semester::factory()->closed()->create(['academic_year_id' => $year->id, 'term' => SemesterTerm::Odd]);
    $this->even = Semester::factory()->active()->create(['academic_year_id' => $year->id, 'term' => SemesterTerm::Even]);

    $class = fn (int $level, Gender $gender) => ClassGroup::factory()->for($this->program)->create([
        'name' => 'Semester '.$level.' '.$gender->classLabel(), 'level' => $level, 'gender' => $gender,
    ]);
    $this->putra1 = $class(1, Gender::Male);
    $this->putri1 = $class(1, Gender::Female);
    $this->putra2 = $class(2, Gender::Male);
    $this->putri2 = $class(2, Gender::Female);

    $this->ahmad = Student::factory()->create(['study_program_id' => $this->program->id]);
    $this->fatimah = Student::factory()->female()->create(['study_program_id' => $this->program->id]);

    $this->actingAs(User::factory()->withRole(Role::Admin)->create());
});

test('levels read as Semester I, II, III …', function () {
    expect(ClassGroup::levelName(1))->toBe('Semester I')
        ->and(ClassGroup::levelName(4))->toBe('Semester IV')
        ->and(ClassGroup::levelName(8))->toBe('Semester VIII')
        ->and(ClassGroup::levelName(14))->toBe('Semester XIV')
        ->and($this->putra2->description())->toBe('Semester II · Putra');
});

test('mahasantri can only be placed in a class of their own gender', function () {
    $manage = app(ManageClassEnrollments::class);

    expect(fn () => $manage->enroll($this->even, $this->putra2, [$this->ahmad->id, $this->fatimah->id]))
        ->toThrow(ValidationException::class, $this->fatimah->user->name);

    expect(ClassEnrollment::query()->count())->toBe(0);

    $manage->enroll($this->even, $this->putra2, [$this->ahmad->id]);
    $manage->enroll($this->even, $this->putri2, [$this->fatimah->id]);

    expect(ClassEnrollment::query()->pluck('class_group_id', 'student_id')->all())
        ->toBe([$this->ahmad->id => $this->putra2->id, $this->fatimah->id => $this->putri2->id]);
});

test('a class without Putra/Putri takes no one new until it is set', function () {
    $legacy = ClassGroup::factory()->for($this->program)->create(['name' => 'Marhalah 1 A', 'level' => 1, 'gender' => null]);

    expect(fn () => app(ManageClassEnrollments::class)->enroll($this->even, $legacy, [$this->ahmad->id]))
        ->toThrow(ValidationException::class, 'Marhalah 1 A');

    $this->get(route('admin.semesters.show', $this->even))->assertOk();
    Livewire::test('pages::admin.semesters.show', ['semester' => $this->even])
        ->call('selectClass', $legacy->id)
        ->assertSeeHtml('data-test="class-gender-missing"')
        ->assertDontSee(__('Add mahasantri: type a NIM or name…'));

    $this->get(route('admin.class-groups'))->assertOk()->assertSeeHtml('data-test="classes-without-gender"');
});

test('copying rosters promotes one semester up, every semester, into the same gender', function () {
    $manage = app(ManageClassEnrollments::class);
    // Placed in the (now closed) odd semester before it was closed.
    ClassEnrollment::create(['semester_id' => $this->odd->id, 'class_group_id' => $this->putra1->id, 'student_id' => $this->ahmad->id, 'class_name' => $this->putra1->name]);
    ClassEnrollment::create(['semester_id' => $this->odd->id, 'class_group_id' => $this->putri1->id, 'student_id' => $this->fatimah->id, 'class_name' => $this->putri1->name]);

    // Odd → even is a promotion too now (it used to keep the same class).
    $this->get(route('admin.semesters.show', $this->even))->assertOk();
    $page = Livewire::test('pages::admin.semesters.show', ['semester' => $this->even])
        ->call('openCopy')
        ->set('copyFromId', $this->odd->id)
        ->assertSet('classMap', [$this->putra1->id => $this->putra2->id, $this->putri1->id => $this->putri2->id]);

    $page->call('copyEnrollments')->assertHasNoErrors();

    expect(ClassEnrollment::query()->where('semester_id', $this->even->id)->pluck('class_group_id', 'student_id')->all())
        ->toBe([$this->ahmad->id => $this->putra2->id, $this->fatimah->id => $this->putri2->id]);

    // A mapping into the wrong gender skips those students instead of mixing the class.
    ClassEnrollment::query()->where('semester_id', $this->even->id)->delete();
    $result = $manage->copy($this->odd, $this->even, [$this->putra1->id => $this->putra2->id, $this->putri1->id => $this->putra2->id]);

    expect($result)->toBe(['placed' => 1, 'skipped' => 1]);
});

test('copying into a class without Putra/Putri is refused', function () {
    $legacy = ClassGroup::factory()->for($this->program)->create(['level' => 2, 'gender' => null]);
    ClassEnrollment::create(['semester_id' => $this->odd->id, 'class_group_id' => $this->putra1->id, 'student_id' => $this->ahmad->id, 'class_name' => $this->putra1->name]);

    expect(fn () => app(ManageClassEnrollments::class)->copy($this->odd, $this->even, [$this->putra1->id => $legacy->id]))
        ->toThrow(ValidationException::class);
});

test('each mahasantri is expected one level above their previous class', function () {
    ClassEnrollment::create(['semester_id' => $this->odd->id, 'class_group_id' => $this->putra1->id, 'student_id' => $this->ahmad->id, 'class_name' => $this->putra1->name]);

    expect(app(ManageClassEnrollments::class)->expectedLevels($this->even, [$this->ahmad->id, $this->fatimah->id]))
        ->toBe([$this->ahmad->id => 2, $this->fatimah->id => 1]);
});

test('the class search lists only the class gender and flags an unexpected level', function () {
    $this->get(route('admin.semesters.show', $this->even))->assertOk();

    Livewire::test('pages::admin.semesters.show', ['semester' => $this->even])
        ->call('selectClass', $this->putri2->id)
        ->set('studentSearch', mb_substr($this->fatimah->nim, 0, 6))
        ->assertSee($this->fatimah->user->name)
        ->assertDontSee($this->ahmad->user->name)
        // Fatimah was never placed: she is expected in Semester I, not this Semester II class.
        ->assertSee(__('Expected: :level', ['level' => 'Semester I']));
});

test('classes need Putra or Putri, and cannot switch while holding the other gender', function () {
    $this->get(route('admin.class-groups'))->assertOk()->assertSee('Semester II')->assertSee('Putri');

    Livewire::test('pages::admin.class-groups')
        ->call('create')
        ->set('studyProgramId', $this->program->id)
        ->set('name', 'Semester III Putra')
        ->set('level', 3)
        ->call('save')
        ->assertHasErrors('gender')
        ->set('gender', Gender::Male->value)
        ->call('save')
        ->assertHasNoErrors();

    expect(ClassGroup::query()->where('name', 'Semester III Putra')->sole())
        ->level->toBe(3)->gender->toBe(Gender::Male);

    app(ManageClassEnrollments::class)->enroll($this->even, $this->putra2, [$this->ahmad->id]);

    Livewire::test('pages::admin.class-groups')
        ->call('edit', $this->putra2->id)
        ->set('gender', Gender::Female->value)
        ->call('save')
        ->assertHasErrors('gender');

    expect($this->putra2->fresh()->gender)->toBe(Gender::Male);
});
