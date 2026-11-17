<?php

use App\Actions\Discussions\ManageDiscussions;
use App\Actions\Enrollment\ManageClassEnrollments;
use App\Enums\DiscussionKind;
use App\Enums\Gender;
use App\Enums\Role;
use App\Enums\StudentStatus;
use App\Models\ClassGroup;
use App\Models\Semester;
use App\Models\Student;
use App\Models\StudyProgram;
use App\Models\Teacher;
use App\Models\User;
use App\Support\StudentPicker;
use Livewire\Livewire;

/*
 * v7 D43: every mahasantri picker lists all active mahasantri straight away, filtered by class, Putra/Putri
 * and a search, 50 at a time.
 */

beforeEach(function () {
    $program = StudyProgram::factory()->create();
    $this->semester = Semester::factory()->active()->create();
    $this->putraA = ClassGroup::factory()->for($program)->create(['name' => 'Putra A', 'level' => 1, 'gender' => Gender::Male]);
    $this->putriB = ClassGroup::factory()->for($program)->create(['name' => 'Putri B', 'level' => 1, 'gender' => Gender::Female]);

    $this->ahmad = Student::factory()->create(['nim' => '2026900001', 'study_program_id' => $program->id]);
    $this->fatimah = Student::factory()->female()->create(['nim' => '2026900002', 'study_program_id' => $program->id]);
    $this->umar = Student::factory()->create(['nim' => '2026900003', 'study_program_id' => $program->id]);
    $this->lulus = Student::factory()->create(['nim' => '2026900004', 'study_program_id' => $program->id, 'status' => StudentStatus::Graduated]);

    $manage = app(ManageClassEnrollments::class);
    $manage->enroll($this->semester, $this->putraA, [$this->ahmad->id]);
    $manage->enroll($this->semester, $this->putriB, [$this->fatimah->id]);

    $this->admin = User::factory()->withRole(Role::Admin)->create();
    $this->actingAs($this->admin);
});

function pickerNames(iterable $students): array
{
    return collect($students)->map(fn ($student) => $student->nim)->sort()->values()->all();
}

test('the filter narrows by class, no class, Putra/Putri and search, and leaves out inactive mahasantri', function () {
    $pick = fn (array $filters = []) => pickerNames(StudentPicker::filter(Student::query(), ...['semester' => $this->semester, ...$filters])->get());

    expect($pick())->toBe(['2026900001', '2026900002', '2026900003'])
        ->and($pick(['class' => $this->putraA->id]))->toBe(['2026900001'])
        ->and($pick(['class' => StudentPicker::NO_CLASS]))->toBe(['2026900003'])
        ->and($pick(['gender' => 'female']))->toBe(['2026900002'])
        ->and($pick(['search' => '900003']))->toBe(['2026900003'])
        ->and($pick(['search' => mb_strtoupper($this->fatimah->user->name)]))->toBe(['2026900002'])
        ->and($pick(['activeOnly' => false]))->toHaveCount(4);
});

test('bimbingan groups list every active mahasantri without typing, and filter them', function () {
    $group = app(ManageDiscussions::class)->create(DiscussionKind::Bimbingan, 'Bimbingan Fiqh', null, Teacher::factory()->create(), $this->admin);

    $page = Livewire::test('pages::bimbingan.groups')->call('select', $group->id)
        ->assertSee($this->ahmad->user->name)->assertSee($this->fatimah->user->name)->assertSee($this->umar->user->name)
        ->assertDontSee($this->lulus->user->name)
        ->assertSee('Putra A');

    $page->set('pickerClass', (string) $this->putriB->id)
        ->assertSee($this->fatimah->user->name)->assertDontSee($this->ahmad->user->name);

    $page->set('pickerClass', '')->set('pickerGender', 'male')
        ->assertSee($this->ahmad->user->name)->assertSee($this->umar->user->name)->assertDontSee($this->fatimah->user->name);
});

test('50 are shown at a time and "load more" adds the next 50', function () {
    Student::factory()->count(55)->create();

    $page = Livewire::test('pages::monitor.activities')->call('create');
    expect($page->get('pickerTotal'))->toBe(58);
    $page->assertSee(__('Showing :shown of :total', ['shown' => 50, 'total' => 58]))
        ->call('morePickerStudents')
        ->assertSee(__('Showing :shown of :total', ['shown' => 58, 'total' => 58]))
        ->set('studentSearch', '2026900')
        ->assertSet('pickerLimit', StudentPicker::PAGE);
});

test('placing in a class lists only that class gender, and who has no class yet', function () {
    $page = Livewire::test('pages::admin.semesters.show', ['semester' => $this->semester])
        ->call('selectClass', $this->putraA->id)
        ->assertSee($this->umar->user->name);
    $listed = fn () => pickerNames($page->instance()->candidates);

    // Putra only; Ahmad is already in the class and shows as such.
    expect($listed())->toBe(['2026900001', '2026900003']);

    $page->set('pickerClass', StudentPicker::NO_CLASS);
    expect($listed())->toBe(['2026900003']);
});

test('the wali form also lists mahasantri who are no longer active', function () {
    Livewire::test('pages::admin.guardians.form')
        ->assertSee($this->ahmad->user->name)
        ->assertSee($this->lulus->user->name)
        ->call('addStudent', $this->ahmad->id)
        ->assertSet('student_ids', [$this->ahmad->id]);
});

test('LPPPK moderation lists mahasantri and muhadhir; a class filter leaves the muhadhir out', function () {
    $teacher = Teacher::factory()->create();

    $page = Livewire::test('pages::lpppk.moderation')
        ->assertSee($this->ahmad->user->name)
        ->assertSee($teacher->user->name)
        ->assertDontSee($this->lulus->user->name);

    $page->set('who', 'teacher')->assertSee($teacher->user->name)->assertDontSee($this->ahmad->user->name);
    $page->set('who', '')->set('pickerClass', (string) $this->putraA->id)
        ->assertSee($this->ahmad->user->name)->assertDontSee($teacher->user->name)->assertDontSee($this->umar->user->name);
});

test('the API pickers need no search and take the same filters', function () {
    $finance = User::factory()->withRole(Role::AdminPayment)->create();
    $token = fn (User $user, Role $role) => $this->withToken($user->createToken('Test')->plainTextToken)->withHeader('X-Role', $role->value);

    app('auth')->forgetGuards();
    $placements = $token($this->admin, Role::Admin)
        ->getJson("/api/v1/admin/semesters/{$this->semester->id}/placements/candidates?class={$this->putraA->id}")
        ->assertOk();
    expect(collect($placements->json('data'))->pluck('nim')->sort()->values()->all())->toBe(['2026900001', '2026900003'])
        ->and($placements->json('meta.total'))->toBe(2);

    app('auth')->forgetGuards();
    $token($this->admin, Role::Admin)->getJson("/api/v1/admin/semesters/{$this->semester->id}/placements/candidates?class_id=none")
        ->assertOk()->assertJsonPath('data.0.nim', '2026900003')->assertJsonCount(1, 'data');

    app('auth')->forgetGuards();
    $token($finance, Role::AdminPayment)->getJson('/api/v1/finance/scholarships/candidates?gender=female')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.class', 'Putri B');

    app('auth')->forgetGuards();
    $token($this->admin, Role::Admin)->getJson("/api/v1/admin/students?class_id={$this->putraA->id}")
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.nim', '2026900001');

    app('auth')->forgetGuards();
    $token($this->admin, Role::Admin)->getJson('/api/v1/admin/students?class_id=abc')->assertUnprocessable();
});
