<?php

use App\Actions\Enrollment\ManageClassEnrollments;
use App\Actions\Enrollment\ManageCourseOfferings;
use App\Actions\Feedback\ManageFeedback;
use App\Actions\Letters\ManageLetters;
use App\Enums\Role;
use App\Models\ClassGroup;
use App\Models\CourseOffering;
use App\Models\LetterType;
use App\Models\Semester;
use App\Models\Student;
use App\Models\StudentDocument;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->admin = User::factory()->withRole(Role::Admin)->create();
    $this->monitor = User::factory()->withRole(Role::AcademicMonitor)->create();
    $this->semester = Semester::factory()->active()->create();
    $this->class = ClassGroup::factory()->create();
    $this->teacher = Teacher::factory()->create();
    $this->student = Student::factory()->withDocuments()->create();
    app(ManageClassEnrollments::class)->enroll($this->semester, $this->class, [$this->student->id]);
});

/**
 * Act through a device token in a role (created directly, not through the rate-limited login).
 */
function asStaff(User $user, Role $role): TestCase
{
    app('auth')->forgetGuards();

    return test()->withToken($user->createToken('Test')->plainTextToken)->withHeader('X-Role', $role->value);
}

test('only staff reach /admin; the monitor only the monitoring endpoints', function () {
    $teacher = $this->teacher->user;
    asStaff($teacher, Role::Teacher)->getJson('/api/v1/admin/students')->assertForbidden();
    asStaff($this->monitor, Role::AcademicMonitor)->getJson('/api/v1/admin/students')->assertForbidden();
    asStaff($this->monitor, Role::AcademicMonitor)->getJson('/api/v1/admin/monitor')->assertOk();
    asStaff($this->admin, Role::Admin)->getJson('/api/v1/admin/students')->assertOk()->assertJsonPath('data.0.nim', $this->student->nim);
});

test('academic structure: a year with its semesters, programs, subjects, rooms and classes', function () {
    asStaff($this->admin, Role::Admin)->postJson('/api/v1/admin/academic-years', ['name' => '2030/2031', 'starts_on' => '2030-07-01', 'ends_on' => '2031-06-30'])->assertCreated();
    $years = asStaff($this->admin, Role::Admin)->getJson('/api/v1/admin/academic-years')->assertOk()->json('data');
    expect(collect($years)->firstWhere('name', '2030/2031')['semesters'])->toHaveCount(2);

    // Only one active semester at a time.
    $planned = collect($years)->firstWhere('name', '2030/2031')['semesters'][0]['id'];
    asStaff($this->admin, Role::Admin)->postJson("/api/v1/admin/semesters/{$planned}/activate")->assertUnprocessable();
    asStaff($this->admin, Role::Admin)->postJson("/api/v1/admin/semesters/{$this->semester->id}/close")->assertOk()->assertJsonPath('data.closed', false);

    $program = asStaff($this->admin, Role::Admin)->postJson('/api/v1/admin/study-programs', ['code' => 'MDH', 'name' => 'Mudzakarah'])->assertOk()->json('data.id');
    asStaff($this->admin, Role::Admin)->postJson('/api/v1/admin/subjects', ['study_program_id' => $program, 'code' => 'USH201', 'name' => 'Ushul Fiqh', 'credits' => 2])->assertOk();
    asStaff($this->admin, Role::Admin)->deleteJson("/api/v1/admin/study-programs/{$program}")->assertUnprocessable();
    asStaff($this->admin, Role::Admin)->postJson('/api/v1/admin/rooms', ['name' => 'R-01', 'capacity' => 30, 'gender' => 'male'])->assertOk()->assertJsonPath('data.gender', 'male');
    asStaff($this->admin, Role::Admin)->postJson('/api/v1/admin/classes', ['study_program_id' => $program, 'gender' => 'female', 'label' => 'Putri B', 'entry_semester_id' => $this->semester->id, 'start_level' => 1])
        ->assertOk()->assertJsonPath('data.level', 1);
    asStaff($this->admin, Role::Admin)->deleteJson("/api/v1/admin/classes/{$this->class->id}")->assertUnprocessable();
    asStaff($this->admin, Role::Admin)->getJson('/api/v1/admin/grade-scale')->assertOk()->assertJsonPath('data.0.letter', fn ($letter) => is_string($letter));
});

test('a semester: classes kuliah with the joint-class question, and placements', function () {
    $subject = Subject::factory()->create(['name' => 'Fiqh Ibadah']);
    $other = ClassGroup::factory()->putri()->create();
    $slot = ['subject_id' => $subject->id, 'teacher_id' => $this->teacher->id, 'day_of_week' => 6, 'starts_at' => '07:30', 'ends_at' => '09:00'];

    asStaff($this->admin, Role::Admin)->postJson("/api/v1/admin/semesters/{$this->semester->id}/classes", [...$slot, 'class_group_id' => $this->class->id])->assertCreated();
    asStaff($this->admin, Role::Admin)->postJson("/api/v1/admin/semesters/{$this->semester->id}/classes", [...$slot, 'class_group_id' => $other->id])
        ->assertUnprocessable()->assertJsonValidationErrors('joint_class');
    asStaff($this->admin, Role::Admin)->postJson("/api/v1/admin/semesters/{$this->semester->id}/classes", [...$slot, 'class_group_id' => $other->id, 'joint' => true])
        ->assertCreated()->assertJsonPath('data.joined_to_id', fn ($id) => $id !== null);
    asStaff($this->admin, Role::Admin)->getJson("/api/v1/admin/semesters/{$this->semester->id}/classes")->assertOk()->assertJsonCount(2, 'data');

    $newcomer = Student::factory()->female()->create();
    asStaff($this->admin, Role::Admin)->getJson("/api/v1/admin/semesters/{$this->semester->id}/placements/candidates?search={$newcomer->nim}&class={$other->id}")
        ->assertOk()->assertJsonPath('data.0.id', $newcomer->id);
    asStaff($this->admin, Role::Admin)->postJson("/api/v1/admin/semesters/{$this->semester->id}/placements", ['class_id' => $other->id, 'student_ids' => [$newcomer->id]])
        ->assertOk()->assertJsonPath('data.placed', 1);
    $roster = asStaff($this->admin, Role::Admin)->getJson("/api/v1/admin/semesters/{$this->semester->id}/placements?class={$other->id}")->assertOk()->json('data.roster');
    expect($roster)->toHaveCount(1);

    // She joined the class kuliah of her class.
    expect(CourseOffering::query()->where('class_group_id', $other->id)->sole()->enrollments()->where('student_id', $newcomer->id)->exists())->toBeTrue();
    asStaff($this->admin, Role::Admin)->deleteJson("/api/v1/admin/semesters/{$this->semester->id}/placements/{$roster[0]['placement_id']}")->assertNoContent();
});

test('exams: a period with a session, rooms and seats, published; a dispensation', function () {
    $offering = app(ManageCourseOfferings::class)->save($this->semester, null, ['subject_id' => Subject::factory()->create()->id, 'class_group_id' => $this->class->id, 'teacher_id' => $this->teacher->id]);
    $room = asStaff($this->admin, Role::Admin)->postJson('/api/v1/admin/rooms', ['name' => 'Aula', 'capacity' => 40, 'gender' => 'male'])->json('data.id');

    $period = asStaff($this->admin, Role::Admin)->postJson("/api/v1/admin/semesters/{$this->semester->id}/exam-periods", ['type' => 'uts', 'starts_on' => today()->addWeek()->toDateString(), 'ends_on' => today()->addWeeks(2)->toDateString()])
        ->assertCreated()->json('data.id');
    asStaff($this->admin, Role::Admin)->postJson("/api/v1/admin/exam-periods/{$period}/sessions", ['course_offering_id' => $offering->id, 'held_on' => today()->addWeek()->toDateString(), 'starts_at' => '08:00', 'ends_at' => '09:30'])->assertCreated();
    asStaff($this->admin, Role::Admin)->putJson("/api/v1/admin/exam-periods/{$period}/rooms", ['room_ids' => [$room]])->assertNoContent();
    asStaff($this->admin, Role::Admin)->postJson("/api/v1/admin/exam-periods/{$period}/seats")->assertOk()->assertJsonPath('data.seated', 1);
    asStaff($this->admin, Role::Admin)->getJson("/api/v1/admin/exam-periods/{$period}")->assertOk()
        ->assertJsonPath('data.sessions.0.rooms', ['Aula'])->assertJsonPath('data.capacity.male.students', 1);
    asStaff($this->admin, Role::Admin)->putJson("/api/v1/admin/exam-periods/{$period}/publish", ['published' => true])->assertOk()->assertJsonPath('data.published', true);

    asStaff($this->admin, Role::Admin)->postJson("/api/v1/admin/exam-periods/{$period}/dispensations/{$this->student->id}", ['letter_number' => '12/DISP/2026', 'letter_date' => today()->toDateString()])->assertNoContent();
    asStaff($this->admin, Role::Admin)->getJson("/api/v1/admin/exam-periods/{$period}/participants")->assertOk()
        ->assertJsonPath('data.0.dispensation.letter_number', '12/DISP/2026')->assertJsonPath('data.0.seat.room', 'Aula');
});

test('people: add a mahasantri, a muhadhir and a wali; temporary passwords; turning a login off', function () {
    $program = $this->student->study_program_id;
    $created = asStaff($this->admin, Role::Admin)->postJson('/api/v1/admin/students', [
        'nim' => '2026199001', 'name' => 'Abdullah', 'study_program_id' => $program, 'entry_year' => 2026, 'gender' => 'male',
    ])->assertCreated()->json('data');
    expect($created['password'])->toBeString()->and($created['username'])->toBe('2026199001');

    asStaff($this->admin, Role::Admin)->post("/api/v1/admin/students/{$created['id']}", ['nim' => '2026199001', 'name' => 'Abdullah Faqih', 'study_program_id' => $program, 'entry_year' => 2026, 'gender' => 'male', 'status' => 'active', 'phone' => '0812'], ['Accept' => 'application/json'])
        ->assertOk()->assertJsonPath('data.name', 'Abdullah Faqih')->assertJsonPath('data.phone', '0812');

    asStaff($this->admin, Role::Admin)->postJson('/api/v1/admin/teachers', ['nidm' => '2026019001', 'name' => 'Ustadz Hasan', 'gender' => 'male'])->assertCreated()->assertJsonPath('data.username', '2026019001');
    $wali = asStaff($this->admin, Role::Admin)->postJson('/api/v1/admin/guardians', ['name' => 'Bapak Faqih', 'student_ids' => [$created['id']]])->assertCreated()->json('data');
    asStaff($this->admin, Role::Admin)->getJson('/api/v1/admin/guardians?search=Abdullah')->assertOk()->assertJsonPath('data.0.code', $wali['username']);

    $user = Student::query()->findOrFail($created['id'])->user;
    asStaff($this->admin, Role::Admin)->postJson("/api/v1/admin/users/{$user->id}/reset-password")->assertOk()->assertJsonPath('data.password', fn ($password) => is_string($password));
    asStaff($this->admin, Role::Admin)->putJson("/api/v1/admin/users/{$user->id}/active", ['active' => false])->assertOk()->assertJsonPath('data.active', false);

    // A staff account is the Superadmin's.
    asStaff($this->admin, Role::Admin)->postJson("/api/v1/admin/users/{$this->monitor->id}/reset-password")->assertForbidden();
});

test('documents are verified or returned; supervisors assigned; activities recorded', function () {
    $document = StudentDocument::query()->where('student_id', $this->student->id)->first();
    $document->update(['file_path' => 'documents/ktp.pdf', 'status' => 'uploaded', 'uploaded_at' => now()]);

    asStaff($this->admin, Role::Admin)->getJson('/api/v1/admin/documents?status=uploaded')->assertOk()->assertJsonPath('data.0.id', $document->id);
    asStaff($this->admin, Role::Admin)->postJson("/api/v1/admin/documents/{$document->id}/review", ['owner' => 'students', 'verify' => false, 'note' => 'Foto buram'])->assertNoContent();
    expect($document->fresh()->status->value)->toBe('rejected');

    asStaff($this->admin, Role::Admin)->postJson('/api/v1/admin/supervision', ['teacher_id' => $this->teacher->id, 'student_ids' => [$this->student->id]])->assertOk()->assertJsonPath('data.assigned', 1);
    $supervision = asStaff($this->admin, Role::Admin)->getJson("/api/v1/admin/supervision?teacher={$this->teacher->id}")->assertOk()->json('data.supervisees.0.supervision_id');
    asStaff($this->admin, Role::Admin)->deleteJson("/api/v1/admin/supervision/{$supervision}")->assertNoContent();

    $log = asStaff($this->monitor, Role::AcademicMonitor)->postJson('/api/v1/admin/activities', ['student_id' => $this->student->id, 'category' => 'ibadah', 'happened_on' => today()->toDateString(), 'title' => 'Khataman'])
        ->assertCreated()->json('data.id');
    asStaff($this->monitor, Role::AcademicMonitor)->getJson('/api/v1/admin/activities')->assertOk()->assertJsonPath('data.0.title', 'Khataman');
    asStaff($this->admin, Role::Admin)->deleteJson("/api/v1/admin/activities/{$log}")->assertNoContent();
});

test('letters: a request is answered with a letter; incoming letters are recorded', function () {
    $type = LetterType::query()->where('requestable', true)->firstOrFail();
    $request = app(ManageLetters::class)->request($this->student, $type->id, 'Beasiswa');

    asStaff($this->admin, Role::Admin)->getJson('/api/v1/admin/letters/overview')->assertOk()->assertJsonPath('data.requests.0.id', $request->id);
    $draft = asStaff($this->admin, Role::Admin)->postJson('/api/v1/admin/letters/draft', ['letter_type_id' => $type->id, 'student_id' => $this->student->id, 'purpose' => 'Beasiswa'])
        ->assertOk()->json('data');
    expect($draft['body'])->toContain($this->student->user->name);

    $letter = asStaff($this->admin, Role::Admin)->postJson('/api/v1/admin/letters', [
        'letter_type_id' => $type->id, 'student_id' => $this->student->id, 'subject_line' => $draft['subject_line'], 'body' => $draft['body'],
        'issued_on' => today()->toDateString(), 'letter_request_id' => $request->id,
    ])->assertCreated()->json('data');
    expect($request->fresh()->status->value)->toBe('approved');
    asStaff($this->admin, Role::Admin)->getJson('/api/v1/admin/letters')->assertOk()->assertJsonPath('data.0.number', $letter['number']);
    asStaff($this->admin, Role::Admin)->postJson("/api/v1/admin/letters/{$letter['id']}/cancel", ['reason' => 'Salah tanggal'])->assertNoContent();

    asStaff($this->admin, Role::Admin)->post('/api/v1/admin/incoming-letters', [
        'received_on' => today()->toDateString(), 'sender' => 'Kemenag Kabupaten', 'subject' => 'Undangan rapat', 'scan' => UploadedFile::fake()->create('undangan.pdf', 80, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.agenda_number', fn ($number) => is_string($number));
    asStaff($this->admin, Role::Admin)->getJson('/api/v1/admin/incoming-letters?open=1')->assertOk()->assertJsonPath('data.0.sender', 'Kemenag Kabupaten');
});

test('monitoring, teaching documents, bimbingan groups, LPPPK, feedback and evaluation', function () {
    $offering = app(ManageCourseOfferings::class)->save($this->semester, null, ['subject_id' => Subject::factory()->create()->id, 'class_group_id' => $this->class->id, 'teacher_id' => $this->teacher->id]);

    asStaff($this->monitor, Role::AcademicMonitor)->getJson('/api/v1/admin/monitor')->assertOk()->assertJsonPath('data.classes.0.id', $offering->id);
    asStaff($this->monitor, Role::AcademicMonitor)->postJson("/api/v1/admin/classes/{$offering->id}/unlock-grades", ['reason' => 'x'])->assertForbidden();
    asStaff($this->monitor, Role::AcademicMonitor)->getJson('/api/v1/admin/teaching-documents?filter=missing')->assertOk()->assertJsonPath('data.0.lead_id', $offering->id);

    $group = asStaff($this->monitor, Role::AcademicMonitor)->postJson('/api/v1/admin/bimbingan-groups', ['name' => 'Bimbingan Skripsi', 'lead_teacher_id' => $this->teacher->id])->assertCreated()->json('data.id');
    asStaff($this->monitor, Role::AcademicMonitor)->postJson("/api/v1/admin/bimbingan-groups/{$group}/members", ['student_ids' => [$this->student->id]])->assertOk()->assertJsonPath('data.added', 1);
    asStaff($this->monitor, Role::AcademicMonitor)->getJson("/api/v1/admin/bimbingan-groups?group={$group}")->assertOk()->assertJsonPath('data.members.0.nim', $this->student->nim);
    asStaff($this->monitor, Role::AcademicMonitor)->putJson("/api/v1/admin/discussions/{$group}/closed", ['closed' => true])->assertNoContent();

    asStaff($this->admin, Role::Admin)->postJson('/api/v1/admin/lpppk-channels', ['name' => 'Pengabdian'])->assertCreated();
    asStaff($this->admin, Role::Admin)->postJson("/api/v1/admin/lpppk-sanctions/{$this->student->user_id}", ['action' => 'mute', 'until' => now()->addDay()->toIso8601String(), 'reason' => 'Spam'])->assertNoContent();
    asStaff($this->admin, Role::Admin)->getJson('/api/v1/admin/lpppk-sanctions')->assertOk()->assertJsonPath('data.0.reason', 'Spam');
    asStaff($this->admin, Role::Admin)->deleteJson("/api/v1/admin/lpppk-sanctions/{$this->student->user_id}")->assertNoContent();

    $feedback = app(ManageFeedback::class)->send($this->student->user, Role::Student, ['category' => 'app', 'subject' => 'Jadwal', 'message' => 'Mohon jadwal bisa diunduh.', 'anonymous' => true]);
    asStaff($this->admin, Role::Admin)->getJson('/api/v1/admin/feedback')->assertOk()->assertJsonPath('data.0.from', __('Anonymous'));
    asStaff($this->admin, Role::Admin)->putJson("/api/v1/admin/feedback/{$feedback->id}", ['reply' => 'Sedang kami siapkan.', 'status' => 'in_progress'])->assertNoContent();
    expect($feedback->fresh()->status->value)->toBe('in_progress');
    asStaff($this->monitor, Role::AcademicMonitor)->getJson('/api/v1/admin/feedback')->assertForbidden();

    asStaff($this->admin, Role::Admin)->postJson("/api/v1/admin/semesters/{$this->semester->id}/evaluation/prepare")->assertOk();
    asStaff($this->admin, Role::Admin)->putJson("/api/v1/admin/semesters/{$this->semester->id}/evaluation", ['opens_on' => today()->toDateString(), 'closes_on' => today()->addWeek()->toDateString(), 'comment_prompt' => 'Saran'])->assertNoContent();
    asStaff($this->admin, Role::Admin)->getJson("/api/v1/admin/semesters/{$this->semester->id}/evaluation")->assertOk()->assertJsonPath('data.period.open', true);
    asStaff($this->monitor, Role::AcademicMonitor)->getJson('/api/v1/admin/evaluations')->assertOk()->assertJsonPath('data.0.shown', false);
});
