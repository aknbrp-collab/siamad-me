<?php

use App\Actions\Enrollment\ManageClassEnrollments;
use App\Actions\Enrollment\ManageCourseOfferings;
use App\Actions\Lms\ManageAssignments;
use App\Actions\Lms\ManageQuizzes;
use App\Actions\Supervision\ManageSupervision;
use App\Enums\Role;
use App\Models\ClassGroup;
use App\Models\CourseEnrollment;
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
        'teacher_id' => $this->teacher->id, 'day_of_week' => 6, 'starts_at' => '07:30', 'ends_at' => '09:00',
    ]);
    $this->students = Student::factory()->count(2)->create();
    app(ManageClassEnrollments::class)->enroll($this->semester, $class, $this->students->modelKeys());
    $this->enrollments = CourseEnrollment::query()->orderBy('id')->get();

    $this->token = $this->postJson('/api/v1/auth/login', ['username' => $this->teacher->user->username, 'password' => 'password', 'device_name' => 'HP'])->json('token');
    $this->base = "/api/v1/teacher/classes/{$this->offering->id}";
});

function asTeacher(): TestCase
{
    return test()->withToken(test()->token);
}

test('the muhadhir lists their classes; another muhadhir cannot open them', function () {
    asTeacher()->getJson('/api/v1/teacher/classes')->assertOk()
        ->assertJsonPath('data.semester_id', $this->semester->id)
        ->assertJsonPath('data.classes.0.subject_name', 'Fiqh Ibadah')
        ->assertJsonPath('data.classes.0.mahasantri_count', 2);

    $other = Teacher::factory()->create();
    $token = $this->postJson('/api/v1/auth/login', ['username' => $other->user->username, 'password' => 'password', 'device_name' => 'HP'])->json('token');
    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson("{$this->base}/meetings")->assertForbidden();
});

test('meetings with the journal, attendance and the summary; a meeting with attendance is kept', function () {
    asTeacher()->postJson("{$this->base}/meetings", ['held_on' => today()->toDateString(), 'mode' => 'online'])
        ->assertUnprocessable()->assertJsonValidationErrors(['meeting_provider', 'meeting_url']);

    $meeting = asTeacher()->postJson("{$this->base}/meetings", [
        'held_on' => today()->toDateString(), 'topic' => 'Thaharah', 'mode' => 'offline', 'material' => 'Bab air', 'method' => 'Ceramah',
    ])->assertCreated()->assertJsonPath('data.meeting_no', 1)->json('data.id');

    $sheet = asTeacher()->getJson("{$this->base}/meetings/{$meeting}/attendance")->assertOk()->assertJsonCount(2, 'data.mahasantri');
    [$first, $second] = array_column($sheet->json('data.mahasantri'), 'enrollment_id');

    asTeacher()->putJson("{$this->base}/meetings/{$meeting}/attendance", ['statuses' => [$first => 'hadir']])->assertUnprocessable();
    asTeacher()->putJson("{$this->base}/meetings/{$meeting}/attendance", ['statuses' => [$first => 'hadir', $second => 'sakit'], 'notes' => [$second => 'Demam']])->assertNoContent();

    asTeacher()->getJson("{$this->base}/meetings")->assertOk()
        ->assertJsonPath('data.meetings.0.present_count', 1)
        ->assertJsonPath('data.meetings.0.material', 'Bab air');
    asTeacher()->deleteJson("{$this->base}/meetings/{$meeting}")->assertUnprocessable();
});

test('materials with files, and assignments graded and copied to the grade sheet', function () {
    asTeacher()->post("{$this->base}/materials", ['title' => 'Kosong'], ['Accept' => 'application/json'])->assertUnprocessable();
    $material = asTeacher()->post("{$this->base}/materials", ['title' => 'Ringkasan', 'file' => UploadedFile::fake()->create('ringkasan.pdf', 50, 'application/pdf')], ['Accept' => 'application/json'])
        ->assertCreated()->json('data');
    $this->app['auth']->forgetGuards();
    $this->get($material['file_url'])->assertOk();
    asTeacher()->deleteJson("{$this->base}/materials/{$material['id']}")->assertNoContent();

    asTeacher()->putJson("{$this->base}/grades/components", ['components' => [['name' => 'Tugas', 'weight' => 40], ['name' => 'UAS', 'weight' => 60]]])->assertOk();
    $component = asTeacher()->getJson("{$this->base}/grades")->json('data.components.0.id');

    $assignment = asTeacher()->postJson("{$this->base}/assignments", ['title' => 'Makalah', 'due_at' => now()->addDay()->toIso8601String(), 'max_score' => 50, 'grade_component_id' => $component])
        ->assertCreated()->json('data.id');
    $submission = app(ManageAssignments::class)->submit($this->offering->assignments()->findOrFail($assignment), $this->enrollments[0], ['text' => 'Isi makalah']);

    $rows = collect(asTeacher()->getJson("{$this->base}/assignments/{$assignment}/submissions")->assertOk()->json('data.mahasantri'));
    expect($rows->firstWhere('enrollment_id', $this->enrollments[0]->id)['submission']['text'])->toBe('Isi makalah')
        ->and($rows->firstWhere('enrollment_id', $this->enrollments[1]->id)['submission'])->toBeNull();
    asTeacher()->putJson("{$this->base}/assignments/{$assignment}/submissions/{$submission->id}/grade", ['score' => 60])->assertUnprocessable();
    asTeacher()->putJson("{$this->base}/assignments/{$assignment}/submissions/{$submission->id}/grade", ['score' => 45, 'feedback' => 'Baik'])->assertNoContent();
    asTeacher()->postJson("{$this->base}/assignments/{$assignment}/copy-to-grades")->assertOk()->assertJsonPath('data.copied', 1);

    $sheet = collect(asTeacher()->getJson("{$this->base}/grades")->json('data.mahasantri'));
    expect($sheet->firstWhere('enrollment_id', $this->enrollments[0]->id)['scores'][$component])->toBe('90.00');
});

test('quizzes are built, published, answered and essays graded', function () {
    $quiz = asTeacher()->postJson("{$this->base}/quizzes", ['title' => 'Kuis', 'opens_at' => now()->subHour()->toIso8601String(), 'closes_at' => now()->addHour()->toIso8601String(), 'max_attempts' => 1])
        ->assertCreated()->json('data.id');
    asTeacher()->postJson("{$this->base}/quizzes/{$quiz}/questions", ['type' => 'single_choice', 'body' => 'Pilih', 'points' => 5, 'options' => [['body' => 'A', 'is_correct' => true]]])
        ->assertUnprocessable();
    asTeacher()->postJson("{$this->base}/quizzes/{$quiz}/questions", ['type' => 'essay', 'body' => 'Jelaskan thaharah.', 'points' => 10])->assertCreated();
    asTeacher()->putJson("{$this->base}/quizzes/{$quiz}/publish", ['published' => true])->assertOk()->assertJsonPath('data.published', true);

    $quizzes = app(ManageQuizzes::class);
    $model = $this->offering->quizzes()->findOrFail($quiz);
    $attempt = $quizzes->start($model, $this->enrollments[0]);
    $quizzes->answer($attempt, $model->questions()->first(), 'Bersuci dari hadas dan najis.');
    $quizzes->submit($attempt);

    $answer = asTeacher()->getJson("{$this->base}/quizzes/{$quiz}")->assertOk()
        ->assertJsonPath('data.attempts.0.needs_manual_grading', true)
        ->assertJsonPath('data.attempts.0.answers.0.text', 'Bersuci dari hadas dan najis.')
        ->json('data.attempts.0.answers.0.id');
    asTeacher()->putJson("{$this->base}/quizzes/{$quiz}/answers/{$answer}/points", ['points' => 11])->assertUnprocessable();
    asTeacher()->putJson("{$this->base}/quizzes/{$quiz}/answers/{$answer}/points", ['points' => 8])->assertNoContent();
    expect($attempt->fresh()->score)->toBe('8.00');
});

test('grades: components add up to 100, scores, the import template, finalising', function () {
    asTeacher()->putJson("{$this->base}/grades/components", ['components' => [['name' => 'UTS', 'weight' => 40]]])->assertUnprocessable();
    $sheet = asTeacher()->putJson("{$this->base}/grades/components", ['components' => [['name' => 'UTS', 'weight' => 40], ['name' => 'UAS', 'weight' => 60]]])->assertOk();
    [$uts, $uas] = array_column($sheet->json('data.components'), 'id');
    [$a, $b] = $this->enrollments->modelKeys();

    asTeacher()->postJson("{$this->base}/grades/finalize")->assertUnprocessable();
    asTeacher()->putJson("{$this->base}/grades/scores", ['scores' => [$a => [$uts => 80, $uas => 90], $b => [$uts => 70, $uas => 75]]])->assertOk()
        ->assertJsonPath('data.mahasantri.0.final_score', fn ($score) => $score !== null);
    asTeacher()->postJson("{$this->base}/grades/finalize")->assertOk()->assertJsonPath('data.finalized', true)->assertJsonPath('data.editable', false);

    $template = asTeacher()->getJson("{$this->base}/grades")->json('data.import_template_url');
    $this->app['auth']->forgetGuards();
    $this->get($template)->assertOk();
});

test('teaching documents: upload a version, templates and the journal PDF', function () {
    asTeacher()->post("{$this->base}/teaching-documents", ['kind' => 'rps', 'file' => UploadedFile::fake()->create('rps.pdf', 100, 'application/pdf')], ['Accept' => 'application/json'])
        ->assertOk()->assertJsonPath('data.kinds.0.versions.0.version', 1)->assertJsonPath('data.kinds.0.versions.0.status', 'waiting');

    $data = asTeacher()->getJson("{$this->base}/teaching-documents")->json('data');
    $this->app['auth']->forgetGuards();
    $this->get($data['journal_pdf_url'])->assertOk()->assertHeader('content-type', 'application/pdf');
    $this->get($data['kinds'][0]['template_url'])->assertOk();
    $this->get($data['kinds'][0]['versions'][0]['file_url'])->assertOk();
});

test('the class forum: a mahasantri starts a thread, the muhadhir replies and pins it', function () {
    $student = $this->students[0];
    $studentToken = $this->postJson('/api/v1/auth/login', ['username' => $student->user->username, 'password' => 'password', 'device_name' => 'HP'])->json('token');
    $this->app['auth']->forgetGuards();

    $thread = $this->withToken($studentToken)->postJson("/api/v1/forums/class/{$this->offering->id}/threads", ['title' => 'Tanya', 'body' => 'Apakah air hujan suci?'])
        ->assertCreated()->json('data.id');
    $this->app['auth']->forgetGuards();

    asTeacher()->postJson("/api/v1/forums/class/{$this->offering->id}/threads/{$thread}/posts", ['body' => 'Ya, air mutlak.'])->assertNoContent();
    asTeacher()->postJson("/api/v1/forums/class/{$this->offering->id}/threads/{$thread}/pin")->assertNoContent();
    asTeacher()->getJson("/api/v1/forums/class/{$this->offering->id}/threads/{$thread}")->assertOk()
        ->assertJsonPath('data.is_pinned', true)->assertJsonPath('data.posts.0.body', 'Ya, air mutlak.');

    // Only the muhadhir moderates (the action refuses, as on the web).
    $this->app['auth']->forgetGuards();
    $this->withToken($studentToken)->postJson("/api/v1/forums/class/{$this->offering->id}/threads/{$thread}/pin")->assertUnprocessable();
});

test('a pembimbing akademik sees their supervisees and their KHS, not their bills', function () {
    $student = $this->students[0];
    app(ManageSupervision::class)->assign($this->teacher, [$student->id], User::factory()->withRole(Role::Admin)->create());

    asTeacher()->getJson('/api/v1/teacher/supervisees')->assertOk()->assertJsonPath('data.0.nim', $student->nim);
    asTeacher()->getJson("/api/v1/students/{$student->id}/khs")->assertOk();
    asTeacher()->getJson("/api/v1/students/{$student->id}/bills")->assertForbidden();
    asTeacher()->getJson("/api/v1/students/{$this->students[1]->id}/khs")->assertForbidden();
    asTeacher()->getJson("/api/v1/forums/supervision/{$this->teacher->id}/threads")->assertOk();
});
