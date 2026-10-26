<?php

use App\Actions\People\UpdateOwnProfile;
use App\Enums\Gender;
use App\Enums\Role;
use App\Enums\StudentStatus;
use App\Enums\TeacherStatus;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

test('a mahasantri fills in their personal and family details', function () {
    Storage::fake('local');
    $student = Student::factory()->create(['entry_year' => 2026]);
    $this->actingAs($student->user);

    $this->get(route('profile.mine'))->assertOk()->assertSee($student->nim)->assertSee(__('Family'));

    Livewire::test('pages::profile.biodata')
        ->assertSet('kind', 'student')
        ->set('birth_place', 'Jombang')
        ->set('birth_date', '2005-03-01')
        ->set('phone', '081234567890')
        ->set('address', 'Jl. Pesantren 1')
        ->set('photo', UploadedFile::fake()->image('me.jpg'))
        ->set('father_name', 'Abdullah')
        ->set('father_is_alive', '1')
        ->set('mother_is_alive', '0')
        ->call('save')
        ->assertHasNoErrors();

    $student->refresh();

    expect($student)
        ->birth_place->toBe('Jombang')
        ->phone->toBe('081234567890')
        ->father_name->toBe('Abdullah')
        ->father_is_alive->toBeTrue()
        ->mother_is_alive->toBeFalse()
        ->entry_year->toBe(2026);

    Storage::disk('local')->assertExists($student->photo_path);
});

test('fields outside the allow-list are ignored, even in a crafted request', function () {
    $student = Student::factory()->create(['entry_year' => 2026, 'status' => StudentStatus::Active]);
    $name = $student->user->name;

    app(UpdateOwnProfile::class)->student($student, [
        'phone' => '0811',
        'nim' => '9999999999',
        'name' => 'Nama Palsu',
        'entry_year' => 2001,
        'status' => StudentStatus::Graduated->value,
        'study_program_id' => 999,
        'gender' => Gender::Female->value,
    ]);

    $student->refresh();
    expect($student->phone)->toBe('0811')
        ->and($student->nim)->not->toBe('9999999999')
        ->and($student->entry_year)->toBe(2026)
        ->and($student->status)->toBe(StudentStatus::Active)
        ->and($student->user->name)->toBe($name);
});

test('a muhadhir edits everything except the NIDM and status', function () {
    $teacher = Teacher::factory()->create(['status' => TeacherStatus::Active]);
    $nidm = $teacher->nidm;
    $this->actingAs($teacher->user)->get(route('profile.mine'))->assertOk()->assertSee($nidm);

    Livewire::test('pages::profile.biodata')
        ->assertSet('kind', 'teacher')
        ->set('name', 'Ahmad Zaini')
        ->set('title', 'Lc., M.A.')
        ->set('nik', '123')
        ->call('save')
        ->assertHasErrors('nik')
        ->set('nik', '3506123456789012')
        ->set('expertise', 'Fiqh Muamalah')
        ->set('google_scholar_url', 'https://scholar.google.com/citations?user=abc')
        ->call('save')
        ->assertHasNoErrors();

    app(UpdateOwnProfile::class)->teacher($teacher->fresh(), [
        'name' => 'Ahmad Zaini', 'gender' => Gender::Male->value,
        'nidm' => '0000000000', 'status' => TeacherStatus::Inactive->value,
    ]);

    $teacher->refresh();
    expect($teacher->user->name)->toBe('Ahmad Zaini')
        ->and($teacher->displayName())->toBe('Ahmad Zaini, Lc., M.A.')
        ->and($teacher->expertise)->toBe('Fiqh Muamalah')
        ->and($teacher->nidm)->toBe($nidm)
        ->and($teacher->user->username)->toBe(strtolower($nidm))
        ->and($teacher->status)->toBe(TeacherStatus::Active);
});

test('a wali santri edits their contact details but not their code or children', function () {
    $guardian = Guardian::factory()->create(['relationship' => 'Ayah']);
    $child = Student::factory()->create();
    $guardian->students()->attach($child);
    $code = $guardian->code;
    $this->actingAs($guardian->user);

    $this->get(route('profile.mine'))->assertOk()->assertSee($code)->assertSee($child->user->name);

    Livewire::test('pages::profile.biodata')
        ->assertSet('relationship', 'Ayah')
        ->set('name', 'Bapak Abdullah')
        ->set('relationship', 'Paman')
        ->set('phone', '0822')
        ->set('email', 'abdullah@example.test')
        ->call('save')
        ->assertHasNoErrors();

    $guardian->refresh();
    expect($guardian->user->name)->toBe('Bapak Abdullah')
        ->and($guardian->user->email)->toBe('abdullah@example.test')
        ->and($guardian->relationship)->toBe('Paman')
        ->and($guardian->phone)->toBe('0822')
        ->and($guardian->code)->toBe($code)
        ->and($guardian->students()->pluck('students.id')->all())->toBe([$child->id]);
});

test('an email already used by another account is refused', function () {
    User::factory()->create(['email' => 'taken@example.test']);
    $guardian = Guardian::factory()->create();
    $this->actingAs($guardian->user)->get(route('profile.mine'))->assertOk();

    Livewire::test('pages::profile.biodata')
        ->set('email', 'taken@example.test')
        ->call('save')
        ->assertHasErrors('email');
});

test('a mahasantri cannot rename themselves in the account settings; others can', function () {
    $student = Student::factory()->create();
    $name = $student->user->name;
    $this->actingAs($student->user);

    $this->get(route('profile.edit'))->assertOk()->assertSee(__('Your name is on official documents and can only be changed by the academic office.'));

    Livewire::test('pages::settings.profile')
        ->set('name', 'Nama Baru')
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    expect($student->user->fresh()->name)->toBe($name);

    $teacher = Teacher::factory()->create();
    $this->actingAs($teacher->user);

    Livewire::test('pages::settings.profile')
        ->set('name', 'Nama Baru')
        ->call('updateProfileInformation');

    expect($teacher->user->fresh()->name)->toBe('Nama Baru');
});

test('profil saya is in the menu of mahasantri, muhadhir and wali, and closed to staff', function (Role $role, bool $allowed) {
    $user = match ($role) {
        Role::Student => Student::factory()->create()->user,
        Role::Teacher => Teacher::factory()->create()->user,
        Role::Guardian => Guardian::factory()->create()->user,
        default => User::factory()->withRole($role)->create(),
    };

    $response = $this->actingAs($user)->get(route('profile.mine'));

    $allowed ? $response->assertOk() : $response->assertForbidden();
    expect(collect($role->navigation())->flatMap(fn ($group) => $group['items'])->pluck('route')->contains('profile.mine'))->toBe($allowed);
})->with([
    [Role::Student, true],
    [Role::Teacher, true],
    [Role::Guardian, true],
    [Role::Admin, false],
    [Role::AdminPayment, false],
]);
