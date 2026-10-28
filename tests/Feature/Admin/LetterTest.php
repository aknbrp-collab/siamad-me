<?php

use App\Actions\Enrollment\ManageClassEnrollments;
use App\Actions\Letters\ManageLetters;
use App\Enums\LetterRequestStatus;
use App\Enums\PrintDocument;
use App\Enums\Role;
use App\Models\ClassGroup;
use App\Models\IncomingLetter;
use App\Models\Letter;
use App\Models\LetterRequest;
use App\Models\LetterType;
use App\Models\Semester;
use App\Models\Setting;
use App\Models\Signatory;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Support\LetterNumbering;
use App\Support\LetterTemplate;
use App\Support\PrintMarks;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo(Carbon::parse('2026-10-28 09:00'));
    Setting::set('institution.name', "Ma'had Aly Contoh");

    $this->semester = Semester::factory()->active()->create();
    $this->student = Student::factory()->create(['nim' => '2026110001']);
    app(ManageClassEnrollments::class)->enroll($this->semester, ClassGroup::factory()->create(['name' => 'Semester I Putra', 'level' => 1]), [$this->student->id]);

    $this->admin = User::factory()->withRole(Role::Admin)->create();
    $this->letters = app(ManageLetters::class);
    $this->ska = LetterType::query()->where('code', 'SKA')->sole();
    $this->st = LetterType::query()->where('code', 'ST')->sole();
    $this->und = LetterType::query()->where('code', 'UND')->sole();
});

function letterInput(LetterType $type, array $overrides = []): array
{
    return ['letter_type_id' => $type->id, 'subject_line' => $type->name, 'body' => 'Isi surat.', 'issued_on' => '2026-10-28', 'print_marks' => true, ...$overrides];
}

test('the five starter letter types exist, with templates', function () {
    expect(LetterType::query()->pluck('code')->sort()->values()->all())->toBe(['REK', 'SKA', 'SKL', 'ST', 'UND'])
        ->and($this->ska->requestable)->toBeTrue()
        ->and($this->ska->template)->toContain('{mahasantri.nama}');
});

test('numbers follow the format, share one counter and restart every year', function () {
    expect(LetterNumbering::render(1, 'SKA', Carbon::parse('2026-10-28')))->toBe('001/MA/SKA/X/2026');

    $first = $this->letters->issue(letterInput($this->ska, ['student_id' => $this->student->id]), $this->admin);
    $second = $this->letters->issue(letterInput($this->und), $this->admin);
    $january = $this->letters->issue(letterInput($this->und, ['issued_on' => '2026-01-15']), $this->admin);

    expect($first->number)->toBe('001/MA/SKA/X/2026')
        ->and($second->number)->toBe('002/MA/UND/X/2026')
        ->and($january->number)->toBe('003/MA/UND/I/2026');

    $this->travelTo(Carbon::parse('2027-01-03 09:00'));
    expect($this->letters->issue(letterInput($this->und, ['issued_on' => '2027-01-03']), $this->admin)->number)->toBe('001/MA/UND/I/2027');
});

test('superadmin sets the format, padding and a starting number to continue a paper register', function () {
    $this->letters->issue(letterInput($this->und), $this->admin);

    expect(fn () => $this->letters->saveNumbering('MA/{kode}', 3, 2026, 1))->toThrow(ValidationException::class, '{nomor}')
        ->and(fn () => $this->letters->saveNumbering('{nomor}/{hijriah}', 3, 2026, 1))->toThrow(ValidationException::class, '{hijriah}')
        ->and(fn () => $this->letters->saveNumbering('{nomor}/{kode}', 3, 2026, 1))->not->toThrow(ValidationException::class);

    $this->letters->saveNumbering('{nomor}.{kode}-MAC/{tahun}', 4, 2026, 120);
    expect($this->letters->issue(letterInput($this->und), $this->admin)->number)->toBe('0120.UND-MAC/2026');

    // A starting number at or below one already used is refused.
    expect(fn () => $this->letters->saveNumbering('{nomor}.{kode}-MAC/{tahun}', 4, 2026, 100))->toThrow(ValidationException::class, 'already used');

    $this->actingAs(User::factory()->withRole(Role::Superadmin)->create())->get(route('superadmin.letter-types'))->assertOk();
    Livewire::test('pages::superadmin.letter-types')
        ->assertSee('0121.SKA-MAC/2026')
        ->set('numberFormat', '{nomor}/MA/{kode}/{bulan-romawi}/{tahun}')
        ->set('padding', 3)
        ->assertSee('121/MA/SKA/X/2026')
        ->call('saveNumbering')
        ->assertHasNoErrors();
});

test('letter types are managed by superadmin; only letters about a mahasantri can be requestable', function () {
    $type = $this->letters->saveType(null, ['name' => 'Surat Izin', 'code' => 'si', 'subject' => 'student', 'requestable' => true, 'template' => 'Izin {mahasantri.nama}', 'print_marks' => false, 'is_active' => true]);
    expect($type->code)->toBe('SI');

    expect(fn () => $this->letters->saveType(null, ['name' => 'X', 'code' => 'SI', 'subject' => 'none', 'requestable' => false, 'template' => 'x']))->toThrow(ValidationException::class)
        ->and(fn () => $this->letters->saveType(null, ['name' => 'Y', 'code' => 'Y', 'subject' => 'teacher', 'requestable' => true, 'template' => 'x']))->toThrow(ValidationException::class, 'mahasantri');

    foreach ([Role::Admin, Role::Student] as $role) {
        $this->actingAs(User::factory()->withRole($role)->create())->get(route('superadmin.letter-types'))->assertForbidden();
    }
});

test('templates are filled from SIAKAD and letters are issued for the right person', function () {
    Setting::set('institution.head', 'KH. Ahmad Fauzi');
    $body = LetterTemplate::fill('{mahasantri.nama} ({mahasantri.nim}), {mahasantri.semester}, {lembaga.nama}, {mudir.nama}, {tanggal}, {keperluan}, {salah}', $this->student, null, Carbon::parse('2026-10-28'), 'beasiswa');

    expect($body)->toBe($this->student->user->name." (2026110001), Semester I Putra, Ma'had Aly Contoh, KH. Ahmad Fauzi, 28 October 2026, beasiswa, {salah}");

    expect(fn () => $this->letters->issue(letterInput($this->ska), $this->admin))->toThrow(ValidationException::class, 'mahasantri')
        ->and(fn () => $this->letters->issue(letterInput($this->st), $this->admin))->toThrow(ValidationException::class, 'muhadhir')
        ->and(fn () => $this->letters->issue(letterInput($this->und, ['issued_on' => '2026-10-29']), $this->admin))->toThrow(ValidationException::class);

    $teacher = Teacher::factory()->create();
    $letter = $this->letters->issue(letterInput($this->st, ['teacher_id' => $teacher->id, 'student_id' => $this->student->id]), $this->admin);
    expect($letter->teacher_id)->toBe($teacher->id)->and($letter->student_id)->toBeNull();
});

test('a letter is cancelled with a reason and its number is never reused', function () {
    $letter = $this->letters->issue(letterInput($this->und), $this->admin);

    expect(fn () => $this->letters->cancel($letter, '', $this->admin))->toThrow(ValidationException::class);
    $this->letters->cancel($letter, 'Salah tanggal acara', $this->admin);

    expect($letter->fresh()->isCancelled())->toBeTrue()
        ->and(fn () => $this->letters->cancel($letter->fresh(), 'Lagi lagi', $this->admin))->toThrow(ValidationException::class, 'already cancelled')
        ->and($this->letters->issue(letterInput($this->und), $this->admin)->sequence)->toBe(2);
});

test('mahasantri request a letter, admin issues it from the request and the mahasantri downloads it', function () {
    $this->actingAs($this->student->user)->get(route('mahasantri.letters'))->assertOk()->assertSee($this->ska->name)->assertDontSee($this->und->name);

    Livewire::test('pages::mahasantri.letters')
        ->set('requestTypeId', $this->und->id)->set('purpose', 'Untuk beasiswa')
        ->call('submit')->assertHasErrors('requestTypeId')
        ->set('requestTypeId', $this->ska->id)->set('purpose', 'Beasiswa Baznas')
        ->call('submit')->assertHasNoErrors()
        ->set('requestTypeId', $this->ska->id)->set('purpose', 'Lagi untuk beasiswa')
        ->call('submit')->assertHasErrors('requestTypeId');

    $request = LetterRequest::query()->sole();
    expect($this->admin->notifications()->sole()->data['title'])->toContain($this->ska->name);

    $this->actingAs($this->admin)->get(route('admin.letters', ['tab' => 'requests']))->assertOk()->assertSee('Beasiswa Baznas');
    Livewire::test('pages::admin.letters')
        ->call('compose', $request->id)
        ->assertSet('studentId', $this->student->id)
        ->assertSet('subjectLine', $this->ska->name)
        ->assertSee('Beasiswa Baznas')
        ->call('issue')
        ->assertHasNoErrors();

    $letter = Letter::query()->sole();
    expect($letter->body)->toContain($this->student->user->name)->toContain('Beasiswa Baznas')
        ->and($request->fresh()->status)->toBe(LetterRequestStatus::Approved)
        ->and($this->student->user->notifications()->sole()->data['body'])->toContain($letter->number);

    $this->actingAs($this->student->user)->withSession(['active_role' => Role::Student->value]);
    $this->get(route('mahasantri.letters'))->assertSee($letter->number);
    $this->get(route('letters.pdf', $letter))->assertOk()->assertHeader('content-type', 'application/pdf');

    $this->letters->cancel($letter, 'Salah keperluan', $this->admin);
    $this->get(route('letters.pdf', $letter))->assertForbidden();
});

test('admin rejects a request with a reason the mahasantri sees', function () {
    $request = $this->letters->request($this->student, $this->ska->id, 'Beasiswa Baznas');

    expect(fn () => $this->letters->reject($request, 'no', $this->admin))->toThrow(ValidationException::class);
    $this->letters->reject($request, 'Tagihan semester belum lunas', $this->admin);

    expect($request->fresh()->status)->toBe(LetterRequestStatus::Rejected)
        ->and(fn () => $this->letters->issue(letterInput($this->ska, ['student_id' => $this->student->id]), $this->admin, $request->fresh()))->toThrow(ValidationException::class, 'already handled');

    $this->actingAs($this->student->user)->get(route('mahasantri.letters'))->assertSee('Tagihan semester belum lunas');
});

test('only admin and the mahasantri concerned open a letter PDF; staff may choose the signer', function () {
    $mudir = Signatory::create(['name' => 'KH. Ahmad Fauzi', 'position' => 'Mudir']);
    $letter = $this->letters->issue(letterInput($this->ska, ['student_id' => $this->student->id, 'signatory_id' => $mudir->id]), $this->admin);

    $this->actingAs($this->admin)->withSession(['active_role' => Role::Admin->value])
        ->get(route('letters.pdf', [$letter, 'marks' => 0, 'signatory' => 0]))->assertOk();
    $this->actingAs(Student::factory()->create()->user)->withSession(['active_role' => Role::Student->value])
        ->get(route('letters.pdf', $letter))->assertForbidden();
    $this->actingAs(User::factory()->withRole(Role::AdminPayment)->create())->withSession(['active_role' => Role::AdminPayment->value])
        ->get(route('letters.pdf', $letter))->assertForbidden();

    $html = view('records.letter', ['letter' => $letter->load('type'), 'document' => PrintDocument::Letter, 'marks' => new PrintMarks($mudir, null, false), 'date' => $letter->issued_on])->render();
    expect($html)->toContain('001/MA/SKA/X/2026')->toContain('KH. Ahmad Fauzi')->toContain('Perihal');
});

test('the admin page writes, lists, cancels and exports letters', function () {
    $this->actingAs($this->admin)->get(route('admin.letters'))->assertOk();

    $page = Livewire::test('pages::admin.letters')
        ->call('compose')
        ->set('letterTypeId', $this->ska->id)
        ->set('personSearch', '2026110001')
        ->assertSee('2026110001 · '.$this->student->user->name)
        ->set('studentId', $this->student->id)
        ->assertSet('subjectLine', $this->ska->name)
        ->call('issue')
        ->assertHasNoErrors()
        ->assertSee('001/MA/SKA/X/2026');

    $letter = Letter::query()->sole();
    expect($letter->body)->toContain('Semester I Putra');

    $page->call('startCancel', $letter->id)->set('cancelReason', 'Salah nama')->call('cancelLetter')->assertHasNoErrors()
        ->call('export')->assertFileDownloaded('register-surat-keluar-2026.xlsx');
});

test('incoming letters get an agenda number, a scan and a disposition', function () {
    $letter = $this->letters->recordIncoming([
        'received_on' => '2026-10-27', 'sender' => 'Kemenag Kab. Situbondo', 'sender_number' => 'B-123/Kk.13.25/PP.00/10/2026',
        'sender_date' => '2026-10-25', 'subject' => 'Undangan rapat koordinasi', 'scan' => UploadedFile::fake()->create('surat.pdf', 100, 'application/pdf'),
        'disposition_to' => 'Mudir', 'disposition_note' => 'Mohon dihadiri',
    ], $this->admin);

    expect($letter->agenda_number)->toBe('AGD/2026/000001')
        ->and(Storage::disk('local')->exists($letter->scan_path))->toBeTrue();

    $this->letters->updateIncoming($letter, [
        'received_on' => '2026-10-27', 'sender' => 'Kemenag Kab. Situbondo', 'subject' => 'Undangan rapat koordinasi',
        'disposition_to' => 'Bagian Akademik', 'disposition_note' => 'Hadir mewakili',
    ], true);
    expect($letter->fresh())
        ->agenda_number->toBe('AGD/2026/000001')
        ->disposition_to->toBe('Bagian Akademik')
        ->disposition_done_at->not->toBeNull()
        ->scan_path->toBe($letter->scan_path);

    $this->actingAs($this->admin)->withSession(['active_role' => Role::Admin->value]);
    $this->get(route('files.show', ['incoming-letter', $letter->id]))->assertOk();
    $this->get(route('admin.incoming-letters'))->assertOk()->assertSee('Kemenag Kab. Situbondo');

    Livewire::test('pages::admin.incoming-letters')
        ->call('create')
        ->set('sender', 'Yayasan')
        ->set('subject', 'Pemberitahuan libur')
        ->call('save')
        ->assertHasNoErrors()
        ->call('export')->assertFileDownloaded('agenda-surat-masuk-2026.xlsx');
    expect(IncomingLetter::query()->latest('id')->value('agenda_number'))->toBe('AGD/2026/000002');

    $this->actingAs($this->student->user)->withSession(['active_role' => Role::Student->value])
        ->get(route('files.show', ['incoming-letter', $letter->id]))->assertForbidden();
});
