<?php

use App\Actions\Enrollment\ManageClassEnrollments;
use App\Actions\Enrollment\ManageCourseOfferings;
use App\Actions\Exams\ExamEligibility;
use App\Actions\Exams\ManageExams;
use App\Actions\Finance\GenerateBills;
use App\Enums\AttendanceStatus;
use App\Enums\BillStatus;
use App\Enums\Gender;
use App\Enums\Role;
use App\Models\Attendance;
use App\Models\Bill;
use App\Models\ClassGroup;
use App\Models\ClassSession;
use App\Models\CourseEnrollment;
use App\Models\CourseOffering;
use App\Models\ExamDispensation;
use App\Models\ExamPeriod;
use App\Models\ExamSeat;
use App\Models\ExamSession;
use App\Models\FeeRate;
use App\Models\FeeType;
use App\Models\Room;
use App\Models\Semester;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->semester = Semester::factory()->active()->create();
    $this->putra = ClassGroup::factory()->create(['name' => 'Semester I Putra', 'level' => 1]);
    $this->putri = ClassGroup::factory()->putri()->create(['name' => 'Semester I Putri', 'level' => 1]);
    $this->ahmad = Teacher::factory()->create();
    $this->hasan = Teacher::factory()->create();
    [$this->fiqh, $this->nahwu] = Subject::factory()->count(2)->create()->all();

    $offerings = app(ManageCourseOfferings::class);
    $this->fiqhPutra = $offerings->save($this->semester, null, ['subject_id' => $this->fiqh->id, 'class_group_id' => $this->putra->id, 'teacher_id' => $this->ahmad->id]);
    $this->nahwuPutra = $offerings->save($this->semester, null, ['subject_id' => $this->nahwu->id, 'class_group_id' => $this->putra->id, 'teacher_id' => $this->hasan->id]);
    $this->fiqhPutri = $offerings->save($this->semester, null, ['subject_id' => $this->fiqh->id, 'class_group_id' => $this->putri->id, 'teacher_id' => $this->ahmad->id]);

    $this->ali = Student::factory()->create(['nim' => '2026110001']);
    $this->umar = Student::factory()->create(['nim' => '2026110002']);
    $this->aisyah = Student::factory()->female()->create(['nim' => '2026110003']);
    $enroll = app(ManageClassEnrollments::class);
    $enroll->enroll($this->semester, $this->putra, [$this->ali->id, $this->umar->id]);
    $enroll->enroll($this->semester, $this->putri, [$this->aisyah->id]);

    $this->r101 = Room::create(['name' => 'R.101', 'capacity' => 30, 'gender' => Gender::Male]);
    $this->r102 = Room::create(['name' => 'R.102', 'capacity' => 30, 'gender' => Gender::Male]);
    $this->r201 = Room::create(['name' => 'R.201', 'capacity' => 30, 'gender' => Gender::Female]);

    $this->exams = app(ManageExams::class);
    $this->uts = $this->exams->savePeriod($this->semester, null, ['type' => 'uts', 'starts_on' => '2026-11-02', 'ends_on' => '2026-11-07']);
});

function examFor(CourseOffering $offering, array $overrides = []): array
{
    return [
        'course_offering_id' => $offering->id, 'held_on' => '2026-11-02', 'starts_at' => '08:00', 'ends_at' => '10:00',
        'invigilator_id' => null, 'room_ids' => [], ...$overrides,
    ];
}

function examEnrollmentOf(Student $student, CourseOffering $offering): CourseEnrollment
{
    return CourseEnrollment::query()->where('student_id', $student->id)->where('course_offering_id', $offering->id)->sole();
}

/**
 * Fiqh (putra, R.101) and Fiqh (putri, R.201) scheduled and seated.
 */
function scheduleAndSeat(object $test): array
{
    $putra = $test->exams->saveSession($test->uts, null, examFor($test->fiqhPutra, ['room_ids' => [$test->r101->id]]));
    $putri = $test->exams->saveSession($test->uts, null, examFor($test->fiqhPutri, ['room_ids' => [$test->r201->id]]));
    $test->exams->assignAllSeats($test->uts);

    return [$putra, $putri];
}

test('a semester has one UTS and one UAS, with the dates in order', function () {
    expect(fn () => $this->exams->savePeriod($this->semester, null, ['type' => 'uts', 'starts_on' => '2026-12-01', 'ends_on' => '2026-12-05']))
        ->toThrow(ValidationException::class, 'This semester already has this exam.');
    expect(fn () => $this->exams->savePeriod($this->semester, null, ['type' => 'uas', 'starts_on' => '2027-01-10', 'ends_on' => '2027-01-05']))
        ->toThrow(ValidationException::class);

    $this->exams->savePeriod($this->semester, null, ['type' => 'uas', 'starts_on' => '2027-01-04', 'ends_on' => '2027-01-09']);

    expect(ExamPeriod::query()->count())->toBe(2);
});

test('the exam date must be within the period and each subject has one exam', function () {
    expect(fn () => $this->exams->saveSession($this->uts, null, examFor($this->fiqhPutra, ['held_on' => '2026-11-09', 'room_ids' => [$this->r101->id]])))
        ->toThrow(ValidationException::class, 'The exam date must be within the exam period.');

    $this->exams->saveSession($this->uts, null, examFor($this->fiqhPutra, ['room_ids' => [$this->r101->id]]));

    expect(fn () => $this->exams->saveSession($this->uts, null, examFor($this->fiqhPutra, ['held_on' => '2026-11-03', 'room_ids' => [$this->r102->id]])))
        ->toThrow(ValidationException::class, 'This subject already has an exam in this period.');
    expect(fn () => $this->exams->saveSession($this->uts, null, examFor($this->nahwuPutra, ['held_on' => '2026-11-03'])))
        ->toThrow(ValidationException::class, 'Choose at least one room.');
});

test('two exams at once cannot share a class, a room or an invigilator', function (string $clash) {
    $this->exams->saveSession($this->uts, null, examFor($this->fiqhPutra, ['room_ids' => [$this->r101->id], 'invigilator_id' => $this->ahmad->id]));

    $input = match ($clash) {
        'class' => examFor($this->nahwuPutra, ['starts_at' => '09:00', 'ends_at' => '11:00', 'room_ids' => [$this->r102->id]]),
        'room' => examFor($this->fiqhPutri, ['room_ids' => [$this->r101->id, $this->r201->id]]),
        'invigilator' => examFor($this->fiqhPutri, ['room_ids' => [$this->r201->id], 'invigilator_id' => $this->ahmad->id]),
    };

    expect(fn () => $this->exams->saveSession($this->uts, null, $input))
        ->toThrow(ValidationException::class, 'same '.$clash);
})->with(['class', 'room', 'invigilator']);

test('back-to-back exams and other days are fine', function () {
    $this->exams->saveSession($this->uts, null, examFor($this->fiqhPutra, ['room_ids' => [$this->r101->id], 'invigilator_id' => $this->ahmad->id]));
    $this->exams->saveSession($this->uts, null, examFor($this->nahwuPutra, ['starts_at' => '10:00', 'ends_at' => '12:00', 'room_ids' => [$this->r101->id], 'invigilator_id' => $this->ahmad->id]));
    $this->exams->saveSession($this->uts, null, examFor($this->fiqhPutri, ['held_on' => '2026-11-03', 'room_ids' => [$this->r101->id], 'invigilator_id' => $this->ahmad->id]));

    expect(ExamSession::query()->count())->toBe(3);
});

test('seats go to rooms of the mahasantri own gender, numbered from 1 per room', function () {
    $this->r101->update(['capacity' => 1]);
    $session = $this->exams->saveSession($this->uts, null, examFor($this->fiqhPutra, ['room_ids' => [$this->r101->id, $this->r102->id, $this->r201->id]]));

    expect($this->exams->assignSeats($session))->toBe(2);

    $seats = ExamSeat::query()->where('exam_session_id', $session->id)->get();
    expect($seats->pluck('room_id')->sort()->values()->all())->toBe([$this->r101->id, $this->r102->id])
        ->and($seats->pluck('seat_number')->all())->toBe([1, 1])
        ->and($session->fresh()->seats_assigned_at)->not->toBeNull();
});

test('seating fails when the rooms of a gender are too small', function () {
    $this->r101->update(['capacity' => 1]);
    $session = $this->exams->saveSession($this->uts, null, examFor($this->fiqhPutra, ['room_ids' => [$this->r101->id, $this->r201->id]]));

    expect(fn () => $this->exams->assignSeats($session))
        ->toThrow(ValidationException::class, ': 2 Putra mahasantri, but Putra rooms seat only 1.');
});

test('changing the rooms clears the seats', function () {
    [$session] = scheduleAndSeat($this);

    $this->exams->saveSession($this->uts, $session, examFor($this->fiqhPutra, ['room_ids' => [$this->r102->id]]));

    expect($session->fresh()->seats_assigned_at)->toBeNull()
        ->and(ExamSeat::query()->where('exam_session_id', $session->id)->count())->toBe(0);
});

test('publishing needs every exam seated, notifies the mahasantri and locks the schedule', function () {
    expect(fn () => $this->exams->publish($this->uts))->toThrow(ValidationException::class, 'Schedule at least one exam before publishing.');

    $this->exams->saveSession($this->uts, null, examFor($this->fiqhPutra, ['room_ids' => [$this->r101->id]]));
    expect(fn () => $this->exams->publish($this->uts))->toThrow(ValidationException::class, 'Assign seats first for: '.$this->fiqh->name);

    $this->exams->assignAllSeats($this->uts);
    $this->exams->publish($this->uts);

    expect($this->uts->fresh()->isPublished())->toBeTrue()
        ->and($this->ali->user->notifications()->sole()->data['title'])->toBe('UTS schedule and exam card')
        ->and($this->aisyah->user->notifications()->count())->toBe(0);

    expect(fn () => $this->exams->saveSession($this->uts->fresh(), null, examFor($this->nahwuPutra, ['room_ids' => [$this->r102->id]])))
        ->toThrow(ValidationException::class, 'This exam period is published');

    $this->exams->publish($this->uts->fresh(), false);
    expect($this->uts->fresh()->isPublished())->toBeFalse();
});

test('the exam card needs the semester bill paid in full', function () {
    scheduleAndSeat($this);
    $spp = FeeType::factory()->create(['name' => 'SPP']);
    FeeRate::create(['fee_type_id' => $spp->id, 'academic_year_id' => $this->semester->academic_year_id, 'amount' => 1_500_000]);
    app(GenerateBills::class)->handle($this->semester, '2026-08-31');

    $eligibility = app(ExamEligibility::class);
    $status = $eligibility->check($this->uts, $this->ali);
    expect($status['payment_ok'])->toBeFalse()
        ->and($status['outstanding'])->toBe(1_500_000)
        ->and($status['eligible'])->toBeFalse();

    Bill::query()->where('student_id', $this->ali->id)->sole()->forceFill(['paid_amount' => 1_500_000, 'status' => BillStatus::Paid])->save();

    expect($eligibility->check($this->uts, $this->ali)['eligible'])->toBeTrue();
});

test('a subject below the attendance minimum is left off the card', function () {
    $this->exams->saveSession($this->uts, null, examFor($this->fiqhPutra, ['room_ids' => [$this->r101->id]]));
    $this->exams->saveSession($this->uts, null, examFor($this->nahwuPutra, ['held_on' => '2026-11-03', 'room_ids' => [$this->r101->id]]));

    $meeting = ClassSession::create(['course_offering_id' => $this->fiqhPutra->id, 'meeting_no' => 1, 'held_on' => '2026-09-05']);
    Attendance::create(['class_session_id' => $meeting->id, 'course_enrollment_id' => examEnrollmentOf($this->ali, $this->fiqhPutra)->id, 'status' => AttendanceStatus::Absent]);
    Attendance::create(['class_session_id' => $meeting->id, 'course_enrollment_id' => examEnrollmentOf($this->umar, $this->fiqhPutra)->id, 'status' => AttendanceStatus::Present]);

    $status = app(ExamEligibility::class)->check($this->uts, $this->ali);
    $subjects = collect($status['subjects'])->mapWithKeys(fn ($subject) => [$subject['session']->course_offering_id => [$subject['attendance'], $subject['ok']]]);

    expect($subjects[$this->fiqhPutra->id])->toBe([0, false])
        ->and($subjects[$this->nahwuPutra->id])->toBe([null, true])
        ->and($status['eligible'])->toBeTrue()
        ->and(collect(app(ExamEligibility::class)->check($this->uts, $this->umar)['subjects'])->every(fn ($subject) => $subject['ok']))->toBeTrue();
});

test('a dispensation lifts both requirements', function () {
    scheduleAndSeat($this);
    $spp = FeeType::factory()->create();
    FeeRate::create(['fee_type_id' => $spp->id, 'academic_year_id' => $this->semester->academic_year_id, 'amount' => 1_500_000]);
    app(GenerateBills::class)->handle($this->semester, '2026-08-31');
    $meeting = ClassSession::create(['course_offering_id' => $this->fiqhPutra->id, 'meeting_no' => 1, 'held_on' => '2026-09-05']);
    Attendance::create(['class_session_id' => $meeting->id, 'course_enrollment_id' => examEnrollmentOf($this->ali, $this->fiqhPutra)->id, 'status' => AttendanceStatus::Absent]);

    expect(app(ExamEligibility::class)->check($this->uts, $this->ali)['eligible'])->toBeFalse();

    $admin = User::factory()->withRole(Role::Admin)->create();
    expect(fn () => $this->exams->grantDispensation($this->uts, $this->ali, ['letter_number' => '12/R/2026', 'letter_date' => now()->addDay()->toDateString()], $admin))
        ->toThrow(ValidationException::class);
    $dispensation = $this->exams->grantDispensation($this->uts, $this->ali, ['letter_number' => '12/R/2026', 'letter_date' => now()->toDateString()], $admin);

    $status = app(ExamEligibility::class)->check($this->uts, $this->ali);
    expect($status['eligible'])->toBeTrue()
        ->and($status['subjects'][0]['ok'])->toBeTrue();

    $this->exams->revokeDispensation($dispensation);
    expect(app(ExamEligibility::class)->check($this->uts, $this->ali)['eligible'])->toBeFalse();
});

test('a mahasantri prints their own card only once the period is published', function () {
    scheduleAndSeat($this);
    $this->actingAs($this->ali->user)->withSession(['active_role' => Role::Student->value]);

    $this->get(route('exams.card', [$this->uts, $this->ali]))->assertForbidden();

    $this->exams->publish($this->uts);

    $this->get(route('exams.card', [$this->uts, $this->ali]))->assertOk()->assertHeader('content-type', 'application/pdf');
    $this->get(route('exams.card', [$this->uts, $this->umar]))->assertForbidden();
});

test('admin prints any eligible card, including one with a dispensation', function () {
    scheduleAndSeat($this);
    $spp = FeeType::factory()->create();
    FeeRate::create(['fee_type_id' => $spp->id, 'academic_year_id' => $this->semester->academic_year_id, 'amount' => 1_500_000]);
    app(GenerateBills::class)->handle($this->semester, '2026-08-31');
    $admin = User::factory()->withRole(Role::Admin)->create();
    $this->actingAs($admin)->withSession(['active_role' => Role::Admin->value]);

    $this->get(route('exams.card', [$this->uts, $this->ali]))->assertForbidden();

    $this->exams->grantDispensation($this->uts, $this->ali, ['letter_number' => '12/R/2026', 'letter_date' => now()->toDateString()], $admin);

    $this->get(route('exams.card', [$this->uts, $this->ali]))->assertOk();
});

test('the admin pages schedule, seat and publish an exam period', function () {
    $this->actingAs(User::factory()->withRole(Role::Admin)->create())->get(route('admin.exams.index'))->assertOk()->assertSee('UTS');
    $this->get(route('admin.rooms'))->assertOk()->assertSee('R.201');

    Livewire::test('pages::admin.rooms')
        ->call('create')
        ->set('name', 'R.301')->set('capacity', 20)->set('gender', 'female')
        ->call('save')
        ->assertHasNoErrors();
    expect(Room::query()->where('name', 'R.301')->value('capacity'))->toBe(20);

    $this->get(route('admin.exams.show', $this->uts))->assertOk();

    Livewire::test('pages::admin.exams.show', ['period' => $this->uts])
        ->call('createSession')
        ->set('course_offering_id', $this->fiqhPutra->id)
        ->set('starts_at', '08:00')->set('ends_at', '10:00')
        ->set('room_ids', [$this->r101->id])
        ->call('saveSession')
        ->assertHasNoErrors()
        ->call('togglePublish')
        ->assertHasErrors('exam')
        ->call('assignAllSeats')
        ->call('togglePublish')
        ->assertHasNoErrors()
        ->set('tab', 'participants')
        ->assertSee($this->ali->nim)
        ->call('openDispensation', $this->umar->id)
        ->set('letterNumber', '7/R/2026')
        ->call('saveDispensation')
        ->assertHasNoErrors();

    expect($this->uts->fresh()->isPublished())->toBeTrue()
        ->and(ExamSeat::query()->count())->toBe(2)
        ->and(ExamDispensation::query()->where('student_id', $this->umar->id)->value('letter_number'))->toBe('7/R/2026');
});

test('the mahasantri page shows the schedule, seats and what is missing', function () {
    scheduleAndSeat($this);
    $spp = FeeType::factory()->create();
    FeeRate::create(['fee_type_id' => $spp->id, 'academic_year_id' => $this->semester->academic_year_id, 'amount' => 1_500_000]);
    app(GenerateBills::class)->handle($this->semester, '2026-08-31');

    $this->actingAs($this->ali->user)->get(route('mahasantri.exams'))->assertOk()->assertSee('No exam schedule has been published yet.');

    $this->exams->publish($this->uts);

    $this->get(route('mahasantri.exams'))
        ->assertOk()
        ->assertSee($this->fiqh->name)
        ->assertSee('Room R.101')
        ->assertSee('data-test="payment-missing"', false)
        ->assertDontSee('data-test="print-card"', false);
});
