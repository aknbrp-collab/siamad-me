<?php

use App\Actions\Enrollment\ManageCourseOfferings;
use App\Enums\Role;
use App\Models\ClassGroup;
use App\Models\CourseOffering;
use App\Models\Semester;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->semester = Semester::factory()->active()->create();
    // Fixed levels: the timetable opens on the first class (by level, then name).
    $this->putra = ClassGroup::factory()->create(['name' => 'Semester I Putra', 'level' => 1]);
    $this->putri = ClassGroup::factory()->putri()->create(['name' => 'Semester I Putri', 'level' => 1]);
    $this->ahmad = Teacher::factory()->create();
    $this->hasan = Teacher::factory()->create();
    [$this->fiqh, $this->nahwu, $this->tafsir] = Subject::factory()->count(3)->create()->all();
    $this->offerings = app(ManageCourseOfferings::class);

    // Saturday 07:30–09:00, Ahmad, Semester I Putra, R.101.
    $this->fiqhPutra = $this->offerings->save($this->semester, null, [
        'subject_id' => $this->fiqh->id, 'class_group_id' => $this->putra->id, 'teacher_id' => $this->ahmad->id,
        'day_of_week' => 6, 'starts_at' => '07:30', 'ends_at' => '09:00', 'room' => 'R.101',
    ]);
});

function slotFor(array $overrides): array
{
    return [...['day_of_week' => 6, 'starts_at' => '08:00', 'ends_at' => '09:30', 'room' => 'R.202'], ...$overrides];
}

test('the same muhadhir, class or room cannot be booked twice at once', function (array $clash) {
    expect(fn () => $this->offerings->save($this->semester, null, slotFor([
        'subject_id' => $this->nahwu->id, 'class_group_id' => $this->putri->id, 'teacher_id' => $this->hasan->id, ...$clash,
    ])))->toThrow(ValidationException::class, $this->fiqh->name);
})->with([
    'same muhadhir' => fn () => ['teacher_id' => $this->ahmad->id],
    'same class' => fn () => ['class_group_id' => $this->putra->id, 'subject_id' => $this->nahwu->id],
    'same room (any case)' => fn () => ['room' => ' r.101 '],
]);

test('back-to-back, other days and unscheduled offerings are fine', function () {
    $offerings = $this->offerings;

    // Starts exactly when Fiqh ends: same muhadhir, class and room.
    $offerings->save($this->semester, null, [
        'subject_id' => $this->nahwu->id, 'class_group_id' => $this->putra->id, 'teacher_id' => $this->ahmad->id,
        'day_of_week' => 6, 'starts_at' => '09:00', 'ends_at' => '10:30', 'room' => 'R.101',
    ]);
    // Same time, another day.
    $offerings->save($this->semester, null, [
        'subject_id' => $this->tafsir->id, 'class_group_id' => $this->putra->id, 'teacher_id' => $this->ahmad->id,
        'day_of_week' => 7, 'starts_at' => '07:30', 'ends_at' => '09:00', 'room' => 'R.101',
    ]);
    // No time yet.
    $offerings->save($this->semester, null, [
        'subject_id' => $this->fiqh->id, 'class_group_id' => $this->putri->id, 'teacher_id' => $this->ahmad->id,
    ]);

    expect(CourseOffering::query()->count())->toBe(4);
});

test('editing an offering does not clash with itself', function () {
    $this->offerings->save($this->semester, $this->fiqhPutra, [
        'subject_id' => $this->fiqh->id, 'class_group_id' => $this->putra->id, 'teacher_id' => $this->ahmad->id,
        'day_of_week' => 6, 'starts_at' => '07:30', 'ends_at' => '09:30', 'room' => 'R.101',
    ]);

    expect($this->fiqhPutra->fresh()->ends_at)->toStartWith('09:30');
});

test('the semester page shows the clash on the time field', function () {
    $this->actingAs(User::factory()->withRole(Role::Admin)->create())->get(route('admin.semesters.show', $this->semester));

    Livewire::test('pages::admin.semesters.show', ['semester' => $this->semester])
        ->call('createOffering')
        ->set('subject_id', $this->nahwu->id)
        ->set('class_group_id', $this->putri->id)
        ->set('teacher_id', $this->ahmad->id)
        ->set('day_of_week', 6)
        ->set('starts_at', '08:00')
        ->set('ends_at', '09:00')
        ->call('saveOffering')
        ->assertHasErrors('starts_at');
});

test('copying offerings skips the ones that would clash, and reports them', function () {
    $next = Semester::factory()->create();
    // Already in the next semester: Hasan in R.101 on Saturday morning.
    $this->offerings->save($next, null, [
        'subject_id' => $this->nahwu->id, 'class_group_id' => $this->putri->id, 'teacher_id' => $this->hasan->id,
        'day_of_week' => 6, 'starts_at' => '08:00', 'ends_at' => '09:00', 'room' => 'R.101',
    ]);

    expect($this->offerings->copy($this->semester, $next))->toBe(['copied' => 0, 'skipped' => 0, 'clashes' => 1]);
});

test('the admin timetable shows a class, a muhadhir or a room for the week', function () {
    $this->actingAs(User::factory()->withRole(Role::Admin)->create())
        ->get(route('admin.timetable'))
        ->assertOk()
        ->assertSeeHtml('data-test="timetable"')
        ->assertSee($this->fiqh->name);

    Livewire::test('pages::admin.timetable')
        ->set('by', 'room')
        ->assertSet('value', 'R.101')
        ->assertSee($this->fiqh->name)
        ->set('by', 'teacher')
        ->set('value', (string) $this->hasan->id)
        ->assertDontSee($this->fiqh->name);
});

test('a muhadhir sees their own timetable', function () {
    $this->actingAs($this->ahmad->user)
        ->get(route('ustadz.timetable'))
        ->assertOk()
        ->assertSee($this->fiqh->name)
        ->assertSee('07:30–09:00');

    $this->actingAs($this->hasan->user)->get(route('ustadz.timetable'))->assertOk()->assertDontSee($this->fiqh->name);
});
