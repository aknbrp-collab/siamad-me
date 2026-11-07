<?php

use App\Actions\Enrollment\ManageClassEnrollments;
use App\Actions\Enrollment\ManageCourseOfferings;
use App\Actions\Evaluations\ManageEvaluations;
use App\Actions\Finance\GenerateBills;
use App\Actions\Finance\ReviewPayments;
use App\Actions\Lms\ManageAssignments;
use App\Actions\Lms\ManageQuizzes;
use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\Bill;
use App\Models\ClassGroup;
use App\Models\FeeRate;
use App\Models\FeeType;
use App\Models\Guardian;
use App\Models\LetterType;
use App\Models\Material;
use App\Models\Payment;
use App\Models\Semester;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->semester = Semester::factory()->active()->create();
    $class = ClassGroup::factory()->create();
    $this->teacher = Teacher::factory()->create();
    $this->offering = app(ManageCourseOfferings::class)->save($this->semester, null, [
        'subject_id' => Subject::factory()->create(['name' => 'Fiqh Ibadah'])->id, 'class_group_id' => $class->id,
        'teacher_id' => $this->teacher->id, 'day_of_week' => now()->dayOfWeekIso, 'starts_at' => '07:30', 'ends_at' => '09:00',
    ]);
    $this->student = Student::factory()->withDocuments()->create();
    $this->classmate = Student::factory()->create();
    app(ManageClassEnrollments::class)->enroll($this->semester, $class, [$this->student->id, $this->classmate->id]);

    $this->token = $this->postJson('/api/v1/auth/login', ['username' => $this->student->user->username, 'password' => 'password', 'device_name' => 'HP'])->json('token');
});

function asStudent(): TestCase
{
    return test()->withToken(test()->token);
}

test('the dashboard and the class list show this semester\'s classes in timetable order', function () {
    app(ManageAssignments::class)->save($this->offering, null, ['title' => 'Ringkasan bab thaharah', 'due_at' => now()->addDays(3), 'max_score' => 100]);

    asStudent()->getJson('/api/v1/student/dashboard')->assertOk()
        ->assertJsonPath('data.today.0.subject_name', 'Fiqh Ibadah')
        ->assertJsonPath('data.today.0.starts_at', '07:30')
        ->assertJsonPath('data.assignments_due.0.title', 'Ringkasan bab thaharah')
        ->assertJsonPath('data.unread_notifications', 1);

    asStudent()->getJson('/api/v1/student/classes')->assertOk()
        ->assertJsonPath('data.0.id', $this->offering->id)
        ->assertJsonPath('data.0.teacher', $this->teacher->user->name)
        ->assertJsonPath('data.0.open_assignments_count', 1);
});

test('a class shows materials with signed file links, and an assignment is submitted with a file', function () {
    Storage::disk('local')->put('materials/ringkasan.pdf', '%PDF-1.4 ringkasan');
    Material::create(['course_offering_id' => $this->offering->id, 'title' => 'Ringkasan', 'file_path' => 'materials/ringkasan.pdf', 'original_name' => 'ringkasan.pdf']);
    $assignment = app(ManageAssignments::class)->save($this->offering, null, ['title' => 'Makalah', 'due_at' => now()->addDay(), 'max_score' => 100]);

    $class = asStudent()->getJson("/api/v1/student/classes/{$this->offering->id}")->assertOk()
        ->assertJsonPath('data.class.subject_name', 'Fiqh Ibadah')
        ->assertJsonPath('data.assignments.0.my_submission', null);
    $this->app['auth']->forgetGuards();
    $this->get($class->json('data.materials.0.file_url'))->assertOk();

    asStudent()->post("/api/v1/student/assignments/{$assignment->id}/submission", [
        'text' => 'Terlampir.', 'file' => UploadedFile::fake()->create('makalah.pdf', 100, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertOk()
        ->assertJsonPath('data.my_submission.text', 'Terlampir.')
        ->assertJsonPath('data.my_submission.file_name', 'makalah.pdf');

    // Someone else's class is closed.
    $other = Student::factory()->create();
    $token = $this->postJson('/api/v1/auth/login', ['username' => $other->user->username, 'password' => 'password', 'device_name' => 'HP'])->json('token');
    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson("/api/v1/student/classes/{$this->offering->id}")->assertForbidden();
});

test('a quiz is started, answered question by question and submitted, without revealing the answers', function () {
    $quizzes = app(ManageQuizzes::class);
    $quiz = $quizzes->save($this->offering, null, ['title' => 'Kuis thaharah', 'opens_at' => now()->subHour(), 'closes_at' => now()->addHour(), 'max_attempts' => 1]);
    $question = $quizzes->saveQuestion($quiz, null, ['type' => 'single_choice', 'body' => 'Air yang suci dan menyucikan?', 'points' => 10, 'options' => [
        ['body' => 'Air mutlak', 'is_correct' => true], ['body' => 'Air musta\'mal', 'is_correct' => false],
    ]]);
    $quizzes->publish($quiz);
    $correct = $question->options()->where('is_correct', true)->value('id');

    $attempt = asStudent()->postJson("/api/v1/student/quizzes/{$quiz->id}/attempts")->assertCreated()
        ->assertJsonPath('data.questions.0.body', 'Air yang suci dan menyucikan?')
        ->assertJsonMissingPath('data.questions.0.options.0.is_correct')
        ->json('data.id');

    asStudent()->putJson("/api/v1/student/quiz-attempts/{$attempt}/answers/{$question->id}", ['answer' => 999999])->assertUnprocessable();
    asStudent()->putJson("/api/v1/student/quiz-attempts/{$attempt}/answers/{$question->id}", ['answer' => $correct])->assertOk()
        ->assertJsonPath('data.questions.0.answer', [$correct])
        ->assertJsonPath('data.score', null);
    asStudent()->postJson("/api/v1/student/quiz-attempts/{$attempt}/submit")->assertOk()->assertJsonPath('data.score', '10.00');

    asStudent()->postJson("/api/v1/student/quizzes/{$quiz->id}/attempts")->assertUnprocessable();
});

test('KHS, transcript and their PDFs; the KHS waits for the evaluation when required', function () {
    asStudent()->getJson("/api/v1/students/{$this->student->id}/khs")->assertOk()
        ->assertJsonPath('data.semester.id', $this->semester->id)
        ->assertJsonPath('data.subjects.0.name', 'Fiqh Ibadah')
        ->assertJsonPath('data.waits_for_evaluation', false);

    $pdf = asStudent()->getJson("/api/v1/students/{$this->student->id}/transcript")->assertOk()->json('data.pdf_url');
    $this->app['auth']->forgetGuards();
    $this->get($pdf)->assertOk()->assertHeader('content-type', 'application/pdf');

    $evaluations = app(ManageEvaluations::class);
    $evaluations->prepareQuestions($this->semester);
    $evaluations->savePeriod($this->semester, ['opens_on' => today(), 'closes_on' => today()->addWeek(), 'comment_prompt' => 'Saran', 'required_for_khs' => true]);

    asStudent()->getJson("/api/v1/students/{$this->student->id}/khs")
        ->assertJsonPath('data.waits_for_evaluation', true)->assertJsonPath('data.subjects', [])->assertJsonPath('data.pdf_url', null);

    $statements = asStudent()->getJson('/api/v1/student/evaluations')->assertOk()->json('data.0.statements');
    asStudent()->postJson("/api/v1/student/evaluations/{$this->offering->id}", [
        'scores' => collect($statements)->mapWithKeys(fn (array $statement) => [$statement['id'] => 4])->all(),
    ])->assertNoContent();
    asStudent()->getJson("/api/v1/students/{$this->student->id}/khs")->assertJsonPath('data.waits_for_evaluation', false);

    // Another mahasantri's records are closed.
    asStudent()->getJson("/api/v1/students/{$this->classmate->id}/khs")->assertForbidden();
});

test('bills: confirm a transfer with proof; a verified payment gets a receipt link', function () {
    $fee = FeeType::factory()->create();
    FeeRate::create(['fee_type_id' => $fee->id, 'academic_year_id' => $this->semester->academic_year_id, 'amount' => 2_000_000]);
    app(GenerateBills::class)->handle($this->semester);
    $bill = Bill::query()->where('student_id', $this->student->id)->sole();

    asStudent()->getJson("/api/v1/students/{$this->student->id}/bills")->assertOk()
        ->assertJsonPath('data.outstanding', 2_000_000)
        ->assertJsonPath('data.bills.0.transfer.open_amount', 2_000_000);

    asStudent()->post("/api/v1/bills/{$bill->id}/transfers", [
        'amount' => 500_000, 'paid_on' => today()->toDateString(), 'reference' => 'BSI-123',
        'proof' => UploadedFile::fake()->image('bukti.jpg'),
    ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.payments.0.status', 'unverified');

    $payment = Payment::query()->sole();
    app(ReviewPayments::class)->verifyTransfer($payment, User::factory()->withRole(Role::AdminPayment)->create());

    $receipt = asStudent()->getJson("/api/v1/students/{$this->student->id}/bills")->json('data.bills.0.payments.0.receipt_url');
    $this->app['auth']->forgetGuards();
    $this->get($receipt)->assertOk()->assertHeader('content-type', 'application/pdf');

    // Not someone else's bill.
    $other = Bill::query()->where('student_id', $this->classmate->id)->sole();
    asStudent()->postJson("/api/v1/bills/{$other->id}/transfers", ['amount' => 500_000, 'paid_on' => today()->toDateString(), 'reference' => 'X'])->assertForbidden();
});

test('letters, documents, supervision, exams and feedback', function () {
    $type = LetterType::query()->where('requestable', true)->where('is_active', true)->firstOrFail();

    asStudent()->getJson('/api/v1/student/letters')->assertOk()->assertJsonFragment(['id' => $type->id, 'name' => $type->name]);
    asStudent()->postJson('/api/v1/student/letter-requests', ['letter_type_id' => $type->id, 'purpose' => 'Beasiswa'])->assertCreated()->assertJsonPath('data.status', 'pending');

    $document = asStudent()->getJson('/api/v1/documents')->assertOk()->json('data.0');
    asStudent()->post("/api/v1/documents/{$document['id']}", ['file' => UploadedFile::fake()->create('ktp.pdf', 200, 'application/pdf')], ['Accept' => 'application/json'])
        ->assertOk()->assertJsonPath('data.status', 'uploaded');

    asStudent()->getJson('/api/v1/student/supervision')->assertOk()->assertJsonPath('data', null);
    asStudent()->getJson('/api/v1/student/exams')->assertOk()->assertJsonPath('data', []);

    asStudent()->postJson('/api/v1/feedback', ['category' => 'app', 'subject' => 'Aplikasi', 'message' => 'Mohon ada mode gelap di aplikasi.'])->assertCreated();
    asStudent()->getJson('/api/v1/feedback')->assertOk()->assertJsonPath('data.0.status', 'new');
});

test('a wali santri sees their children, each child\'s records and bills, and nobody else\'s', function () {
    $guardian = Guardian::factory()->create();
    $guardian->students()->attach($this->student->id);
    ActivityLog::query()->create(['student_id' => $this->student->id, 'category' => 'ibadah', 'happened_on' => today(), 'title' => 'Khataman', 'recorded_by' => User::factory()->withRole(Role::Admin)->create()->id]);
    $token = $this->postJson('/api/v1/auth/login', ['username' => $guardian->user->username, 'password' => 'password', 'device_name' => 'HP Wali'])->json('token');
    $this->app['auth']->forgetGuards();

    $this->withToken($token)->getJson('/api/v1/guardian/children')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.nim', $this->student->nim);
    $this->withToken($token)->getJson("/api/v1/students/{$this->student->id}/khs")->assertOk();
    $this->withToken($token)->getJson("/api/v1/students/{$this->student->id}/bills")->assertOk();
    $this->withToken($token)->getJson("/api/v1/students/{$this->student->id}/activities")->assertOk()->assertJsonPath('data.0.title', 'Khataman');

    $this->withToken($token)->getJson("/api/v1/students/{$this->classmate->id}/bills")->assertForbidden();
    $this->withToken($token)->getJson('/api/v1/student/classes')->assertForbidden();
});
