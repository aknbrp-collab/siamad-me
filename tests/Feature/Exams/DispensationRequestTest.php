<?php

use App\Actions\Enrollment\ManageClassEnrollments;
use App\Actions\Enrollment\ManageCourseOfferings;
use App\Actions\Exams\ManageDispensationRequests;
use App\Actions\Exams\ManageExams;
use App\Actions\Finance\GenerateBills;
use App\Enums\BillStatus;
use App\Enums\Role;
use App\Models\Bill;
use App\Models\ClassGroup;
use App\Models\DispensationRequest;
use App\Models\FeeRate;
use App\Models\FeeType;
use App\Models\Guardian;
use App\Models\Semester;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * v7 D47: a mahasantri whose semester bill is not paid off writes a dispensation request, prints the
 * letter for the Mudir, and Admin grants it as an exam dispensation.
 */

beforeEach(function () {
    $this->semester = Semester::factory()->active()->create();
    $class = ClassGroup::factory()->create(['name' => 'Semester I Putra', 'level' => 1]);
    $offering = app(ManageCourseOfferings::class)->save($this->semester, null, [
        'subject_id' => Subject::factory()->create()->id, 'class_group_id' => $class->id, 'teacher_id' => Teacher::factory()->create()->id,
    ]);

    $this->ali = Student::factory()->create(['nim' => '2026120001']);
    $this->umar = Student::factory()->create(['nim' => '2026120002']);
    app(ManageClassEnrollments::class)->enroll($this->semester, $class, [$this->ali->id, $this->umar->id]);

    $spp = FeeType::factory()->create(['name' => 'SPP']);
    FeeRate::create(['fee_type_id' => $spp->id, 'academic_year_id' => $this->semester->academic_year_id, 'amount' => 1_500_000]);
    app(GenerateBills::class)->handle($this->semester, '2026-08-31');

    $this->admin = User::factory()->withRole(Role::Admin)->create();
    $this->period = app(ManageExams::class)->savePeriod($this->semester, null, ['type' => 'uts', 'starts_on' => '2026-11-02', 'ends_on' => '2026-11-07']);
    app(ManageExams::class)->saveSession($this->period, null, [
        'course_offering_id' => $offering->id, 'held_on' => '2026-11-02', 'starts_at' => '08:00', 'ends_at' => '10:00', 'invigilator_id' => null,
    ]);
});

test('a mahasantri with an unpaid bill writes the request, Admin is told once, and the letter prints', function () {
    $this->actingAs($this->ali->user);

    $page = Livewire::test('pages::mahasantri.bills')
        ->assertSee(__('Write a request'))
        ->call('writeDispensation')
        ->set('dispensationReason', 'short')
        ->call('saveDispensation')
        ->assertHasErrors('reason')
        ->set('dispensationReason', 'Orang tua baru panen bulan depan; insya Allah lunas pada 15 Desember.')
        ->call('saveDispensation')
        ->assertHasNoErrors()
        ->assertSee(__('Waiting for the Mudir'))
        ->assertSee(__('Print the letter'));

    $request = DispensationRequest::query()->sole();
    expect($request->student_id)->toBe($this->ali->id)
        ->and($request->semester_id)->toBe($this->semester->id)
        ->and($request->outstanding)->toBe(1_500_000)
        ->and($this->admin->notifications()->sole()->data['title'])->toContain($this->ali->user->name);

    // Writing again replaces the reason; Admin is not told twice.
    $page->call('writeDispensation')
        ->assertSet('dispensationReason', $request->reason)
        ->set('dispensationReason', 'Alasan yang diperbarui: pembayaran menunggu gaji bulan depan.')
        ->call('saveDispensation');
    expect(DispensationRequest::query()->sole()->reason)->toStartWith('Alasan yang diperbarui')
        ->and($this->admin->notifications()->count())->toBe(1);

    $this->get(route('dispensations.letter', $request))->assertOk()->assertHeader('content-type', 'application/pdf');
});

test('the letter shows the kop, the student, the bills, the reason and an empty place for the Mudir', function () {
    $request = app(ManageDispensationRequests::class)->submit($this->ali, 'Menunggu kiriman dari orang tua bulan depan.', $this->ali->user);
    $requests = app(ManageDispensationRequests::class);

    $html = view('records.dispensation', [
        'request' => $request->load('semester.academicYear'),
        'student' => $this->ali->load(['user', 'studyProgram']),
        'className' => 'Semester I Putra',
        'guardians' => 'Bapak Ali',
        'bills' => $requests->bills($this->ali, $this->semester),
        'signatory' => null,
        'head' => 'KH. Mudir',
        'institution' => "Ma'had Aly",
        'city' => 'Situbondo',
    ])->render();

    expect($html)->toContain('SURAT PERMOHONAN DISPENSASI')
        ->toContain($this->ali->nim)
        ->toContain('Bapak Ali')
        ->toContain(Bill::query()->where('student_id', $this->ali->id)->value('number'))
        ->toContain('Menunggu kiriman dari orang tua bulan depan.')
        ->toContain('KH. Mudir')
        ->toContain('Menyetujui');
});

test('nothing to ask for once the semester bill is paid off', function () {
    Bill::query()->where('student_id', $this->ali->id)->update(['status' => BillStatus::Paid, 'paid_amount' => 1_500_000]);
    $this->actingAs($this->ali->user);

    Livewire::test('pages::mahasantri.bills')->assertDontSee(__('Write a request'));

    expect(fn () => app(ManageDispensationRequests::class)->submit($this->ali, 'Alasan yang cukup panjang di sini.', $this->ali->user))
        ->toThrow(ValidationException::class);
});

test('the letter is for the mahasantri, their wali, academic and finance staff only', function () {
    $request = app(ManageDispensationRequests::class)->submit($this->ali, 'Menunggu kiriman dari orang tua bulan depan.', $this->ali->user);
    $guardian = Guardian::factory()->create();
    $guardian->students()->attach($this->ali->id);

    $this->actingAs($this->umar->user)->get(route('dispensations.letter', $request))->assertForbidden();
    $this->actingAs(Teacher::factory()->create()->user)->get(route('dispensations.letter', $request))->assertForbidden();
    $this->actingAs($guardian->user)->get(route('dispensations.letter', $request))->assertOk();
    $this->actingAs(User::factory()->withRole(Role::AdminPayment)->create())->get(route('dispensations.letter', $request))->assertOk();

    $this->actingAs($guardian->user);
    Livewire::test('pages::wali.bills')
        ->assertSee(__('Waiting for the Mudir'))
        ->assertSee('Menunggu kiriman dari orang tua')
        ->assertDontSee(__('Change the reason'));
});

test('Admin sees the request on the exam period and grants it from there', function () {
    $request = app(ManageDispensationRequests::class)->submit($this->ali, 'Menunggu kiriman dari orang tua bulan depan.', $this->ali->user);
    $this->actingAs($this->admin);

    Livewire::test('pages::admin.exams.show', ['period' => $this->period])
        ->set('tab', 'participants')
        ->assertSeeHtml('data-test="request-'.$this->ali->id.'"')
        ->assertDontSeeHtml('data-test="request-'.$this->umar->id.'"')
        ->call('openDispensation', $this->ali->id)
        ->assertSet('letterNote', fn (string $note) => str_contains($note, 'Menunggu kiriman'))
        ->set('letterNumber', '012/MA/XI/2026')
        ->call('saveDispensation')
        ->assertHasNoErrors();

    expect(app(ManageDispensationRequests::class)->granted($request))->toBeTrue();

    $this->actingAs($this->ali->user);
    Livewire::test('pages::mahasantri.bills')->assertSee(__('Granted'));
});

test('the app reads and writes the request, and opens the letter through a signed link', function () {
    $token = fn (User $user, Role $role) => $this->withToken($user->createToken('Test')->plainTextToken)->withHeader('X-Role', $role->value);

    app('auth')->forgetGuards();
    $token($this->ali->user, Role::Student)->getJson("/api/v1/students/{$this->ali->id}/dispensation")
        ->assertOk()->assertJsonPath('data.can_request', true)->assertJsonPath('data.request', null);

    app('auth')->forgetGuards();
    $token($this->ali->user, Role::Student)->postJson('/api/v1/student/dispensation', ['reason' => 'pendek'])->assertUnprocessable();

    app('auth')->forgetGuards();
    $written = $token($this->ali->user, Role::Student)->postJson('/api/v1/student/dispensation', ['reason' => 'Menunggu kiriman dari orang tua bulan depan.'])
        ->assertOk()->assertJsonPath('data.request.granted', false);

    app('auth')->forgetGuards();
    $this->get($written->json('data.request.letter_url'))->assertOk()->assertHeader('content-type', 'application/pdf');

    app('auth')->forgetGuards();
    $token($this->umar->user, Role::Student)->getJson("/api/v1/students/{$this->ali->id}/dispensation")->assertForbidden();

    app('auth')->forgetGuards();
    $participants = $token($this->admin, Role::Admin)->getJson("/api/v1/admin/exam-periods/{$this->period->id}/participants")->assertOk();
    $ali = collect($participants->json('data'))->firstWhere('student_id', $this->ali->id);
    expect($ali['dispensation_request']['reason'])->toBe('Menunggu kiriman dari orang tua bulan depan.')
        ->and(collect($participants->json('data'))->firstWhere('student_id', $this->umar->id)['dispensation_request'])->toBeNull();
});
