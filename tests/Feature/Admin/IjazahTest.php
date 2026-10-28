<?php

use App\Actions\Documents\UploadDocument;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\EducationLevel;
use App\Enums\Role;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
});

function scan(): UploadedFile
{
    return UploadedFile::fake()->create('ijazah.pdf', 200, 'application/pdf');
}

test('a mahasantri ijazah needs its level, institution and graduation year', function () {
    $student = Student::factory()->withDocuments()->create();
    $ijazah = $student->documents()->where('type', DocumentType::Ijazah)->sole();
    $this->actingAs($student->user)->withSession(['active_role' => Role::Student->value]);

    Livewire::test('pages::profile.documents')
        ->assertSee(__('Graduation year'))
        ->assertDontSee(__('Gelar from this ijazah'))
        ->set("uploads.{$ijazah->id}", scan())
        ->call('upload', $ijazah->id)
        ->assertHasErrors(["details.{$ijazah->id}.education_level", "details.{$ijazah->id}.institution", "details.{$ijazah->id}.graduation_year"])
        // A mahasantri enters with MA/SMA or pesantren, not a degree.
        ->set("details.{$ijazah->id}.education_level", 's1')
        ->set("details.{$ijazah->id}.institution", 'MA Al-Hikmah Kediri')
        ->set("details.{$ijazah->id}.graduation_year", 2025)
        ->call('upload', $ijazah->id)
        ->assertHasErrors("details.{$ijazah->id}.education_level")
        ->set("details.{$ijazah->id}.education_level", 'ma')
        ->call('upload', $ijazah->id)
        ->assertHasNoErrors();

    expect($ijazah->fresh())
        ->status->toBe(DocumentStatus::Uploaded)
        ->education_level->toBe(EducationLevel::Ma)
        ->graduation_year->toBe(2025)
        ->title()->toBe(__('Ijazah :level', ['level' => EducationLevel::Ma->label()]));

    // KTP needs no details.
    $ktp = $student->documents()->where('type', DocumentType::Ktp)->sole();
    Livewire::test('pages::profile.documents')
        ->set("uploads.{$ktp->id}", scan())
        ->call('upload', $ktp->id)
        ->assertHasNoErrors();
});

test('a muhadhir adds an ijazah per level, each with its gelar', function () {
    $teacher = Teacher::factory()->create();
    foreach (DocumentType::cases() as $type) {
        $teacher->documents()->create(['type' => $type]);
    }
    $first = $teacher->documents()->where('type', DocumentType::Ijazah)->sole();
    $this->actingAs($teacher->user);

    $page = Livewire::test('pages::profile.documents')
        ->call('addIjazah')
        ->assertHasErrors('ijazah')
        ->set("details.{$first->id}.education_level", 's1')
        ->set("details.{$first->id}.institution", 'Universitas Al-Azhar, Kairo')
        ->set("details.{$first->id}.graduation_year", 2012)
        ->set("details.{$first->id}.degree_title", 'Lc.')
        ->set("uploads.{$first->id}", scan())
        ->call('upload', $first->id)
        ->assertHasNoErrors()
        ->call('addIjazah')
        ->assertHasNoErrors();

    $second = $teacher->documents()->where('type', DocumentType::Ijazah)->whereKeyNot($first->id)->sole();

    // The same level twice is refused.
    $page->set("details.{$second->id}.education_level", 's1')
        ->set("details.{$second->id}.institution", 'UIN Sunan Ampel')
        ->set("details.{$second->id}.graduation_year", 2016)
        ->set("uploads.{$second->id}", scan())
        ->call('upload', $second->id)
        ->assertHasErrors("details.{$second->id}.education_level")
        ->set("details.{$second->id}.education_level", 's2')
        ->set("details.{$second->id}.degree_title", 'M.Pd.I.')
        ->call('upload', $second->id)
        ->assertHasNoErrors();

    expect($first->fresh()->details())->toBe('Universitas Al-Azhar, Kairo · 2012 · Lc.')
        ->and($second->fresh()->degree_title)->toBe('M.Pd.I.');

    // An empty extra ijazah can be removed; an uploaded one cannot.
    $page->call('addIjazah');
    $third = $teacher->documents()->where('type', DocumentType::Ijazah)->whereNull('file_path')->sole();
    $page->call('removeIjazah', $third->id)->assertHasNoErrors();
    expect($third->fresh())->toBeNull()
        ->and(fn () => app(UploadDocument::class)->removeIjazah($second->fresh()))->toThrow(ValidationException::class);

    $this->actingAs(User::factory()->withRole(Role::Admin)->create())
        ->get(route('admin.documents', ['owner' => 'teachers']))
        ->assertSee(__('Ijazah :level', ['level' => EducationLevel::S2->label()]))
        ->assertSee('UIN Sunan Ampel · 2016 · M.Pd.I.');
});
