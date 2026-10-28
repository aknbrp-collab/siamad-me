<?php

use App\Actions\Enrollment\ManageClassEnrollments;
use App\Actions\Enrollment\ManageCourseOfferings;
use App\Actions\Teaching\ManageMeetings;
use App\Actions\Teaching\ManageTeachingDocuments;
use App\Enums\Role;
use App\Enums\TeachingDocumentKind;
use App\Enums\TeachingDocumentStatus;
use App\Models\ClassGroup;
use App\Models\Semester;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeachingDocument;
use App\Models\User;
use App\Support\TeachingJournal;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->semester = Semester::factory()->active()->create();
    $this->putra = ClassGroup::factory()->create(['name' => 'Semester I Putra', 'level' => 1]);
    $this->putri = ClassGroup::factory()->putri()->create(['name' => 'Semester I Putri', 'level' => 1]);
    $this->teacher = Teacher::factory()->create();
    $this->other = Teacher::factory()->create();
    $this->fiqh = Subject::factory()->create(['code' => 'FQH101', 'name' => 'Fiqh Ibadah']);
    $nahwu = Subject::factory()->create();

    $offerings = app(ManageCourseOfferings::class);
    $this->fiqhPutra = $offerings->save($this->semester, null, ['subject_id' => $this->fiqh->id, 'class_group_id' => $this->putra->id, 'teacher_id' => $this->teacher->id]);
    $this->fiqhPutri = $offerings->save($this->semester, null, ['subject_id' => $this->fiqh->id, 'class_group_id' => $this->putri->id, 'teacher_id' => $this->teacher->id]);
    $this->nahwuPutra = $offerings->save($this->semester, null, ['subject_id' => $nahwu->id, 'class_group_id' => $this->putra->id, 'teacher_id' => $this->other->id]);

    app(ManageClassEnrollments::class)->enroll($this->semester, $this->putra, [Student::factory()->create()->id]);
    app(ManageClassEnrollments::class)->enroll($this->semester, $this->putri, [Student::factory()->female()->create()->id]);

    $this->docs = app(ManageTeachingDocuments::class);
    $this->admin = User::factory()->withRole(Role::Admin)->create();
});

function rpsFile(): UploadedFile
{
    return UploadedFile::fake()->create('RPS Fiqh.pdf', 120, 'application/pdf');
}

test('admin joins classes of the same subject and muhadhir; they share one set of documents', function () {
    expect(fn () => $this->docs->join([$this->fiqhPutra->id]))->toThrow(ValidationException::class, 'at least two')
        ->and(fn () => $this->docs->join([$this->fiqhPutra->id, $this->nahwuPutra->id]))->toThrow(ValidationException::class, 'same subject');

    $lead = $this->docs->join([$this->fiqhPutri->id, $this->fiqhPutra->id]);

    expect($lead->id)->toBe($this->fiqhPutra->id)
        ->and($this->fiqhPutri->fresh()->joined_to_id)->toBe($this->fiqhPutra->id)
        ->and($this->docs->members($this->fiqhPutri->fresh())->pluck('id')->all())->toBe([$this->fiqhPutra->id, $this->fiqhPutri->id]);

    // Uploaded from the putri class, it belongs to the group.
    $document = $this->docs->upload($this->fiqhPutri->fresh(), TeachingDocumentKind::Rps, rpsFile(), $this->teacher->user);
    expect($document->course_offering_id)->toBe($this->fiqhPutra->id)
        ->and($this->docs->latest($this->fiqhPutra, TeachingDocumentKind::Rps)?->is($document))->toBeTrue();

    expect(fn () => $this->docs->split($this->fiqhPutra))->toThrow(ValidationException::class, 'stay joined');
});

test('a class without documents can join one whose lead already has them', function () {
    $document = $this->docs->upload($this->fiqhPutra, TeachingDocumentKind::Rps, rpsFile(), $this->teacher->user);

    $this->docs->join([$this->fiqhPutra->id, $this->fiqhPutri->id]);

    expect($this->docs->latest($this->fiqhPutri->fresh(), TeachingDocumentKind::Rps)?->is($document))->toBeTrue();
});

test('joined classes can be separated while nothing is uploaded', function () {
    $this->docs->join([$this->fiqhPutra->id, $this->fiqhPutri->id]);
    $this->docs->split($this->fiqhPutri->fresh());

    expect($this->fiqhPutri->fresh()->joined_to_id)->toBeNull();
});

test('every upload is a new version; admin accepts or returns it with a note', function () {
    expect(fn () => $this->docs->upload($this->fiqhPutra, TeachingDocumentKind::Rps, rpsFile(), $this->other->user))->toThrow(ValidationException::class, 'Only the muhadhir')
        ->and(fn () => $this->docs->upload($this->fiqhPutra, TeachingDocumentKind::Rps, UploadedFile::fake()->create('x.exe', 10), $this->teacher->user))->toThrow(ValidationException::class);

    $first = $this->docs->upload($this->fiqhPutra, TeachingDocumentKind::Rps, rpsFile(), $this->teacher->user);
    expect($first->version)->toBe(1)->and($first->status)->toBe(TeachingDocumentStatus::Waiting)
        ->and($this->admin->notifications()->sole()->data['title'])->toContain('RPS');

    expect(fn () => $this->docs->review($first, false, 'no', $this->admin))->toThrow(ValidationException::class);
    $this->docs->review($first, false, 'Lengkapi capaian pembelajaran', $this->admin);
    expect($first->fresh()->status)->toBe(TeachingDocumentStatus::Returned)
        ->and($this->teacher->user->notifications()->sole()->data['body'])->toBe('Lengkapi capaian pembelajaran');

    $second = $this->docs->upload($this->fiqhPutra, TeachingDocumentKind::Rps, rpsFile(), $this->teacher->user);
    expect($second->version)->toBe(2)
        ->and(Storage::disk('local')->exists($first->file_path))->toBeTrue()
        ->and(fn () => $this->docs->review($first->fresh(), true, null, $this->admin))->toThrow(ValidationException::class, 'latest version');

    $this->docs->review($second, true, null, $this->admin);
    expect($second->fresh()->status)->toBe(TeachingDocumentStatus::Accepted);
});

test('the journal merges the meetings of joined classes by date', function () {
    $this->docs->join([$this->fiqhPutra->id, $this->fiqhPutri->id]);
    $meetings = app(ManageMeetings::class);
    $meetings->save($this->fiqhPutra, null, ['held_on' => '2026-09-05', 'topic' => 'Thaharah', 'mode' => 'offline', 'material' => 'Air mutlak dan musta\'mal', 'method' => 'Bandongan']);
    $meetings->save($this->fiqhPutri, null, ['held_on' => '2026-09-05', 'topic' => null, 'mode' => 'offline']);
    $meetings->save($this->fiqhPutri, null, ['held_on' => '2026-09-12', 'topic' => 'Wudhu', 'mode' => 'offline', 'journal_note' => 'Praktik']);

    $rows = TeachingJournal::rows($this->docs->members($this->fiqhPutra));

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['topic'])->toBe('Thaharah')
        ->and($rows[0]['material'])->toBe("Air mutlak dan musta'mal")
        ->and($rows[1]['note'])->toBe('Praktik');

    $this->actingAs($this->teacher->user)->withSession(['active_role' => Role::Teacher->value])
        ->get(route('teaching.journal', $this->fiqhPutri))->assertOk()->assertHeader('content-type', 'application/pdf');
    $this->actingAs($this->other->user)->withSession(['active_role' => Role::Teacher->value])
        ->get(route('teaching.journal', $this->fiqhPutra))->assertForbidden();
});

test('the muhadhir uploads from the Dokumen tab and sees the review', function () {
    $this->actingAs($this->teacher->user)->get(route('ustadz.offerings.documents', $this->fiqhPutra))->assertOk()->assertSee(__('Not uploaded'));
    $this->actingAs($this->other->user)->get(route('ustadz.offerings.documents', $this->fiqhPutra))->assertForbidden();

    $this->actingAs($this->teacher->user);
    Livewire::test('pages::ustadz.offerings.documents', ['offering' => $this->fiqhPutra])
        ->set('files.rps', rpsFile())
        ->call('upload', 'rps')
        ->assertHasNoErrors()
        ->assertSee(__('Version :version', ['version' => 1]))
        ->set('files.journal', UploadedFile::fake()->create('jurnal.pdf', 80, 'application/pdf'))
        ->call('upload', 'journal')
        ->assertHasNoErrors();

    $document = TeachingDocument::query()->where('kind', 'rps')->sole();
    $this->withSession(['active_role' => Role::Teacher->value])->get(route('files.show', ['teaching-document', $document->id]))->assertOk();
    $this->actingAs($this->other->user)->withSession(['active_role' => Role::Teacher->value])->get(route('files.show', ['teaching-document', $document->id]))->assertForbidden();
});

test('admin reviews and joins on the overview; the monitor only looks', function () {
    $this->docs->upload($this->fiqhPutra, TeachingDocumentKind::Rps, rpsFile(), $this->teacher->user);
    $document = TeachingDocument::query()->sole();

    $this->actingAs($this->admin)->get(route('teaching-documents'))->assertOk()->assertSee('Fiqh Ibadah')->assertSee(__('Waiting for review'));

    Livewire::test('pages::teaching-documents')
        ->set('filter', 'waiting')
        ->assertSee('Fiqh Ibadah')
        ->assertDontSee($this->nahwuPutra->subject_name)
        ->call('startReview', $document->id)
        ->call('review', true)
        ->assertHasNoErrors()
        ->set('filter', 'all')
        ->call('startJoin', $this->nahwuPutra->id)
        ->call('export')->assertFileDownloaded();
    expect($document->fresh()->status)->toBe(TeachingDocumentStatus::Accepted);

    // Fiqh putri has its own document and is not the lowest offering: it cannot join putra.
    $this->docs->upload($this->fiqhPutri, TeachingDocumentKind::Rps, rpsFile(), $this->teacher->user);
    Livewire::test('pages::teaching-documents')
        ->call('startJoin', $this->fiqhPutri->id)
        ->set('joinIds', [$this->fiqhPutra->id])
        ->call('join')
        ->assertHasErrors('join');

    $monitor = User::factory()->withRole(Role::AcademicMonitor)->create();
    $this->actingAs($monitor)->get(route('teaching-documents'))->assertOk()->assertDontSee('data-test="review-', false);
    Livewire::test('pages::teaching-documents')->call('startReview', $document->id)->assertForbidden();

    $this->actingAs($this->teacher->user)->get(route('teaching-documents'))->assertForbidden();
});
