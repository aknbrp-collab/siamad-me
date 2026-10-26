<?php

use App\Enums\DocumentStatus;
use App\Enums\Role;
use App\Enums\StudentStatus;
use App\Models\Student;
use App\Models\StudyProgram;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->admin = User::factory()->withRole(Role::Admin)->create();
    $this->actingAs($this->admin);
    $this->program = StudyProgram::factory()->create(['code' => 'MA']);
});

test('admin can create a mahasantri with a login and missing-document rows', function () {
    $this->get(route('admin.students.create'))->assertOk();

    $component = Livewire::test('pages::admin.students.form')
        ->set('nim', '2026110099')
        ->set('name', 'Ahmad Fauzi')
        ->set('gender', 'male')
        ->set('study_program_id', $this->program->id)
        ->set('entry_year', 2026)
        ->set('father_name', 'Abdullah')
        ->set('father_is_alive', '0')
        ->set('photo', UploadedFile::fake()->image('foto.jpg'))
        ->call('save')
        ->assertHasNoErrors();

    $student = Student::query()->with(['user', 'documents'])->where('nim', '2026110099')->sole();
    $password = $component->get('credentialPassword');

    expect($student->user)
        ->username->toBe('2026110099')
        ->must_change_password->toBeTrue()
        ->and($student->user->hasRole(Role::Student))->toBeTrue()
        ->and(Hash::check($password, $student->user->password))->toBeTrue()
        ->and($student->father_is_alive)->toBeFalse()
        ->and($student->documents->pluck('status')->unique()->all())->toBe([DocumentStatus::Missing])
        ->and($student->documents)->toHaveCount(3);

    Storage::disk('local')->assertExists($student->photo_path);

    // The password is forgotten when the modal closes, then we land on the edit page.
    $component->call('forgetCredentials')
        ->assertSet('credentialPassword', null)
        ->assertRedirect(route('admin.students.edit', $student));
});

test('nim must not clash with any existing login', function () {
    User::factory()->create(['username' => '2026110001']);

    Livewire::test('pages::admin.students.form')
        ->set('nim', '2026110001')
        ->set('name', 'X')
        ->set('gender', 'male')
        ->set('study_program_id', $this->program->id)
        ->call('save')
        ->assertHasErrors('nim');
});

test('editing a mahasantri updates the login too', function () {
    $student = Student::factory()->for($this->program)->create();

    $this->get(route('admin.students.edit', $student))->assertOk()->assertSee($student->nim);

    Livewire::test('pages::admin.students.form', ['student' => $student])
        ->set('nim', '2026119999')
        ->set('name', 'Nama Baru')
        ->set('status', StudentStatus::Graduated->value)
        ->call('save')
        ->assertHasErrors('graduated_at')
        ->set('graduated_at', '2026-06-30')
        ->call('save')
        ->assertHasNoErrors();

    expect($student->fresh()->load('user'))
        ->nim->toBe('2026119999')
        ->status->toBe(StudentStatus::Graduated)
        ->and($student->fresh()->user)->username->toBe('2026119999')->name->toBe('Nama Baru');
});

test('admin can reset a password and disable a login', function () {
    $student = Student::factory()->create();

    $component = Livewire::test('pages::admin.students.form', ['student' => $student])
        ->call('resetPassword');

    $user = $student->user->fresh();
    expect($user->must_change_password)->toBeTrue()
        ->and(Hash::check($component->get('credentialPassword'), $user->password))->toBeTrue();

    $component->call('toggleActive');
    expect($user->fresh()->is_active)->toBeFalse();
});

test('the student list filters and exports', function () {
    Student::factory()->for($this->program)->count(2)->create();
    $graduated = Student::factory()->for($this->program)->create(['status' => StudentStatus::Graduated, 'graduated_at' => '2026-01-01']);

    $this->get(route('admin.students.index'))->assertOk();

    Livewire::test('pages::admin.students.index')
        ->assertDontSee($graduated->nim)
        ->set('status', 'graduated')
        ->assertSee($graduated->nim)
        ->call('export')
        ->assertFileDownloaded();
});

test('photos are only served to staff and their owner', function () {
    $student = Student::factory()->create(['photo_path' => 'photos/students/a.jpg']);
    Storage::disk('local')->put('photos/students/a.jpg', 'x');

    $this->get(route('files.show', ['student-photo', $student->id]))->assertOk();

    $this->actingAs($student->user)->get(route('files.show', ['student-photo', $student->id]))->assertOk();

    $other = Student::factory()->create();
    $this->actingAs($other->user)->get(route('files.show', ['student-photo', $student->id]))->assertForbidden();
});
