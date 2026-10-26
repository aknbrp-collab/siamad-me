<?php

use App\Enums\DocumentStatus;
use App\Enums\Role;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\StudentDocument;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
});

test('admin can create and edit an ustadz', function () {
    $this->actingAs(User::factory()->withRole(Role::Admin)->create());
    $this->get(route('admin.teachers.create'))->assertOk();

    $component = Livewire::test('pages::admin.teachers.form')
        ->set('nidm', '2026010077')
        ->set('name', 'Ahmad Zaini')
        ->set('title', 'Lc., M.A.')
        ->set('gender', 'male')
        ->set('nik', '123')
        ->call('save')
        ->assertHasErrors('nik')
        ->set('nik', '3506123456789012')
        ->set('google_scholar_url', 'https://scholar.google.com/citations?user=abc')
        ->call('save')
        ->assertHasNoErrors();

    $teacher = Teacher::query()->where('nidm', '2026010077')->sole();

    expect($teacher->displayName())->toBe('Ahmad Zaini, Lc., M.A.')
        ->and($teacher->user->hasRole(Role::Teacher))->toBeTrue()
        ->and($teacher->documents()->count())->toBe(3)
        ->and($component->get('credentialUsername'))->toBe('2026010077');

    $this->get(route('admin.teachers.edit', $teacher))->assertOk()->assertSee('Ahmad Zaini, Lc., M.A.');
    $this->get(route('admin.teachers.index'))->assertOk()->assertSee('2026010077');
});

test('admin can create a wali santri linked to several mahasantri', function () {
    $this->actingAs(User::factory()->withRole(Role::Admin)->create());
    [$first, $second] = Student::factory()->count(2)->create();

    $this->get(route('admin.guardians.create', ['student' => $first->id]))->assertOk()->assertSee($first->nim);

    Livewire::withQueryParams(['student' => $first->id])
        ->test('pages::admin.guardians.form')
        ->assertSet('student_ids', [$first->id])
        ->set('name', 'Bapak Abdullah')
        ->set('studentSearch', $second->nim)
        ->call('addStudent', $second->id)
        ->call('save')
        ->assertHasNoErrors();

    $guardian = Guardian::query()->with(['user', 'students'])->sole();

    expect($guardian->code)->toMatch('/^WS-[A-Z2-9]{8}$/')
        ->and($guardian->user->username)->toBe(strtolower($guardian->code))
        ->and($guardian->students->pluck('id')->sort()->values()->all())->toBe([$first->id, $second->id]);
});

test('a wali santri needs at least one mahasantri', function () {
    $this->actingAs(User::factory()->withRole(Role::Admin)->create());

    Livewire::test('pages::admin.guardians.form')
        ->set('name', 'Tanpa Anak')
        ->call('save')
        ->assertHasErrors('student_ids');
});

test('mahasantri upload documents and admin verifies or rejects them', function () {
    $student = Student::factory()->withDocuments()->create();
    $this->actingAs($student->user);

    $this->get(route('mahasantri.dashboard'))->assertSee(__('Please upload your documents'));
    $this->get(route('documents.mine'))->assertOk()->assertSee(__('KTP (identity card)'));

    Livewire::test('pages::profile.documents')
        ->set('uploads.ktp', UploadedFile::fake()->create('ktp.exe', 10))
        ->call('upload', 'ktp')
        ->assertHasErrors('uploads.ktp')
        ->set('uploads.ktp', UploadedFile::fake()->create('ktp.pdf', 300, 'application/pdf'))
        ->call('upload', 'ktp')
        ->assertHasNoErrors();

    $ktp = StudentDocument::query()->where('type', 'ktp')->sole();
    expect($ktp->status)->toBe(DocumentStatus::Uploaded);
    Storage::disk('local')->assertExists($ktp->file_path);

    $this->actingAs($admin = User::factory()->withRole(Role::Admin)->create());
    $this->get(route('admin.documents'))->assertOk()->assertSee($student->nim);
    $this->get(route('files.show', ['student-document', $ktp->id]))->assertOk();

    Livewire::test('pages::admin.documents')
        ->call('startReject', $ktp->id)
        ->call('reject')
        ->assertHasErrors('rejectionNote')
        ->set('rejectionNote', 'Foto buram')
        ->call('reject')
        ->assertHasNoErrors();

    expect($ktp->fresh())->status->toBe(DocumentStatus::Rejected)->rejection_note->toBe('Foto buram');

    // The owner sees the note, re-uploads, and the admin verifies.
    $this->actingAs($student->user);
    $this->get(route('documents.mine'))->assertSee('Foto buram');
    Livewire::test('pages::profile.documents')
        ->set('uploads.ktp', UploadedFile::fake()->image('ktp.jpg'))
        ->call('upload', 'ktp');

    $this->actingAs($admin);
    Livewire::test('pages::admin.documents')->call('verify', $ktp->id)->assertHasNoErrors();

    expect($ktp->fresh())->status->toBe(DocumentStatus::Verified)->verified_by->toBe($admin->id);
});

test('documents of other people cannot be opened', function () {
    $student = Student::factory()->withDocuments()->create();
    $document = $student->documents()->first();
    $document->update(['file_path' => 'documents/x.pdf']);
    Storage::disk('local')->put('documents/x.pdf', 'x');

    $other = Student::factory()->create();

    $this->actingAs($other->user)->get(route('files.show', ['student-document', $document->id]))->assertForbidden();
    $this->actingAs($student->user)->get(route('files.show', ['student-document', $document->id]))->assertOk();
});

test('only mahasantri and ustadz have a documents page', function () {
    $this->actingAs(User::factory()->withRole(Role::Guardian)->create())
        ->get(route('documents.mine'))
        ->assertForbidden();
});
