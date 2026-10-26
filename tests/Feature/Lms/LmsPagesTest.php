<?php

use App\Actions\Enrollment\ManageClassEnrollments;
use App\Actions\Enrollment\ManageCourseOfferings;
use App\Actions\Lms\ManageAssignments;
use App\Actions\Lms\ManageQuizzes;
use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\Assignment;
use App\Models\ClassGroup;
use App\Models\CourseEnrollment;
use App\Models\ForumPost;
use App\Models\ForumThread;
use App\Models\Guardian;
use App\Models\Material;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\Semester;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Submission;
use App\Models\Teacher;
use App\Models\User;
use App\Support\ActiveRole;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->semester = Semester::factory()->active()->create();
    $classGroup = ClassGroup::factory()->create();
    $this->teacher = Teacher::factory()->create();
    $this->offering = app(ManageCourseOfferings::class)->save($this->semester, null, [
        'subject_id' => Subject::factory()->create(['name' => 'Fiqh Ibadah'])->id, 'class_group_id' => $classGroup->id,
        'teacher_id' => $this->teacher->id, 'day_of_week' => 6,
    ]);
    $this->student = Student::factory()->create();
    app(ManageClassEnrollments::class)->enroll($this->semester, $classGroup, [$this->student->id]);
    $this->enrollment = CourseEnrollment::query()->sole();
});

function as_(User $user): void
{
    test()->actingAs($user);
    ActiveRole::set($user->roleList()[0]);
}

test('the ustadz shares a material and the mahasantri opens it', function () {
    as_($this->teacher->user);
    $this->get(route('ustadz.offerings.materials', $this->offering))->assertOk();

    Livewire::test('pages::ustadz.offerings.materials', ['offering' => $this->offering])
        ->call('create')
        ->set('title', 'Ringkasan Fathul Qarib')
        ->call('save')
        ->assertHasErrors('title')
        ->set('file', UploadedFile::fake()->create('ringkasan.pdf', 200, 'application/pdf'))
        ->call('save')
        ->assertHasNoErrors();

    $material = Material::query()->sole();

    as_($this->student->user);
    $this->get(route('mahasantri.classes'))->assertOk()->assertSee('Fiqh Ibadah');
    $this->get(route('mahasantri.class', ['offering' => $this->offering, 'tab' => 'materials']))->assertOk()->assertSee('Ringkasan Fathul Qarib');
    $this->get(route('files.show', ['material', $material->id]))->assertOk();

    as_(Student::factory()->create()->user);
    $this->get(route('files.show', ['material', $material->id]))->assertForbidden();
    $this->get(route('mahasantri.class', $this->offering))->assertForbidden();
});

test('assignment flow: submit from the class page, grade from the submissions page', function () {
    as_($this->teacher->user);
    Livewire::test('pages::ustadz.offerings.assignments', ['offering' => $this->offering])
        ->call('create')
        ->set('title', 'Makalah Thaharah')
        ->call('save')
        ->assertHasNoErrors();
    $assignment = Assignment::query()->sole();

    as_($this->student->user);
    Livewire::test('pages::mahasantri.class', ['offering' => $this->offering])
        ->set('tab', 'assignments')
        ->call('openSubmit', $assignment->id)
        ->set('file', UploadedFile::fake()->create('makalah.pdf', 100, 'application/pdf'))
        ->call('submit')
        ->assertHasNoErrors();
    $submission = Submission::query()->sole();

    as_($this->teacher->user);
    $this->get(route('ustadz.offerings.assignment', [$this->offering, $assignment]))->assertOk()->assertSee('makalah.pdf');
    $this->get(route('files.show', ['submission', $submission->id]))->assertOk();

    Livewire::test('pages::ustadz.offerings.assignment', ['offering' => $this->offering, 'assignment' => $assignment])
        ->set("scores.{$submission->id}", '150')
        ->call('grade', $submission->id)
        ->assertHasErrors("scores.{$submission->id}")
        ->set("scores.{$submission->id}", '90')
        ->set("feedback.{$submission->id}", 'Rapi')
        ->call('grade', $submission->id)
        ->assertHasNoErrors();

    expect($submission->fresh())->score->toBe('90.00')->feedback->toBe('Rapi');

    as_(Teacher::factory()->create()->user);
    $this->get(route('files.show', ['submission', $submission->id]))->assertForbidden();
});

test('quiz flow: author, publish, take with autosave, auto-grade', function () {
    as_($this->teacher->user);
    Livewire::test('pages::ustadz.offerings.quizzes', ['offering' => $this->offering])
        ->call('create')
        ->set('title', 'Kuis 1')
        ->set('opensAt', now()->subHour()->format('Y-m-d\TH:i'))
        ->call('save')
        ->assertHasNoErrors();
    $quiz = Quiz::query()->sole();

    Livewire::test('pages::ustadz.offerings.quiz', ['offering' => $this->offering, 'quiz' => $quiz])
        ->call('newQuestion')
        ->set('body', 'Air yang suci dan mensucikan disebut…')
        ->set('options.0.body', 'Air mutlak')
        ->set('options.1.body', 'Air musta\'mal')
        ->call('saveQuestion')
        ->assertHasNoErrors()
        ->call('newQuestion')
        ->set('type', 'true_false')
        ->set('body', 'Tayammum boleh dengan debu suci.')
        ->call('saveQuestion')
        ->assertHasNoErrors()
        ->call('togglePublish')
        ->assertHasNoErrors();

    expect($quiz->fresh()->isPublished())->toBeTrue();

    as_($this->student->user);
    Livewire::test('pages::mahasantri.class', ['offering' => $this->offering])
        ->set('tab', 'quizzes')
        ->call('startQuiz', $quiz->id);
    $attempt = QuizAttempt::query()->sole();

    [$q1, $q2] = $quiz->questions()->with('options')->get()->all();
    $this->get(route('mahasantri.quiz-attempt', $attempt))->assertOk()->assertSee('Air mutlak');

    Livewire::test('pages::mahasantri.quiz-attempt', ['attempt' => $attempt])
        ->set("answers.{$q1->id}", $q1->options[0]->id)
        ->set("answers.{$q2->id}", $q2->options[1]->id)
        ->call('submit');

    expect($attempt->fresh())->score->toBe('1.00')->max_score->toBe(2);

    as_(Student::factory()->create()->user);
    $this->get(route('mahasantri.quiz-attempt', $attempt))->assertForbidden();
});

test('answers save when the browser replaces the whole answers array (checkbox groups)', function () {
    $quizzes = app(ManageQuizzes::class);
    $quiz = $quizzes->save($this->offering, null, ['title' => 'K', 'opens_at' => now()->subHour(), 'closes_at' => now()->addDay(), 'max_attempts' => 1]);
    $quizzes->saveQuestion($quiz, null, ['type' => 'multiple_choice', 'body' => 'Rukun wudhu', 'points' => 3, 'options' => [
        ['body' => 'Niat', 'is_correct' => true], ['body' => 'Membasuh muka', 'is_correct' => true], ['body' => 'Berkumur', 'is_correct' => false],
    ]]);
    $quizzes->publish($quiz);
    $attempt = $quizzes->start($quiz, $this->enrollment);
    $question = $quiz->questions()->with('options')->sole();
    as_($this->student->user);

    Livewire::test('pages::mahasantri.quiz-attempt', ['attempt' => $attempt])
        ->set('answers', [$question->id => [(string) $question->options[0]->id, (string) $question->options[1]->id]])
        ->call('submit');

    expect($attempt->fresh()->score)->toBe('3.00');
});

test('opening an expired attempt hands it in', function () {
    $quizzes = app(ManageQuizzes::class);
    $quiz = $quizzes->save($this->offering, null, ['title' => 'K', 'opens_at' => now()->subHour(), 'closes_at' => now()->addDay(), 'max_attempts' => 1, 'duration_minutes' => 5]);
    $quizzes->saveQuestion($quiz, null, ['type' => 'essay', 'body' => 'Jelaskan.', 'points' => 5]);
    $quizzes->publish($quiz);
    $attempt = $quizzes->start($quiz, $this->enrollment);

    $this->travel(10)->minutes();
    as_($this->student->user);

    $this->get(route('mahasantri.quiz-attempt', $attempt))->assertOk()->assertSee(__('Submitted'));
    expect($attempt->fresh()->submitted_at)->not->toBeNull();
});

test('forum: the mahasantri asks, the ustadz answers and locks', function () {
    as_($this->student->user);
    Livewire::test('pages::shared.forum', ['offering' => $this->offering])
        ->set('newTitle', 'Najis mughallazhah')
        ->set('newBody', 'Bagaimana cara mensucikannya?')
        ->call('startThread')
        ->assertHasNoErrors();
    $thread = ForumThread::query()->sole();

    as_($this->teacher->user);
    $this->get(route('ustadz.offerings.forum', $this->offering))->assertOk()->assertSee('Najis mughallazhah');
    Livewire::test('pages::shared.forum', ['offering' => $this->offering])
        ->call('open', $thread->id)
        ->set('reply', 'Dibasuh tujuh kali, salah satunya dengan debu.')
        ->call('postReply')
        ->assertHasNoErrors()
        ->call('toggleLock');

    expect(ForumPost::query()->count())->toBe(1)->and($thread->fresh()->is_locked)->toBeTrue();

    as_($this->student->user);
    Livewire::test('pages::shared.forum', ['offering' => $this->offering])
        ->call('open', $thread->id)
        ->set('reply', 'Terima kasih')
        ->call('postReply')
        ->assertHasErrors('reply');
});

test('the monitor records activities; wali see them; missing work is counted', function () {
    $assignment = app(ManageAssignments::class)->save($this->offering, null, ['title' => 'Lewat', 'due_at' => now()->subDay(), 'max_score' => 100]);

    $monitor = User::factory()->withRole(Role::AcademicMonitor)->create();
    as_($monitor);
    $this->get(route('monitor.activities'))->assertOk();

    Livewire::test('pages::monitor.activities')
        ->call('create')
        ->set('title', 'Setoran Juz 30')
        ->call('save')
        ->assertHasErrors('studentId')
        ->set('studentSearch', $this->student->nim)
        ->call('choose', $this->student->id)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Setoran Juz 30');

    expect(ActivityLog::query()->sole()->recorded_by)->toBe($monitor->id);

    Livewire::test('pages::monitor.dashboard')->assertSet('missingWork', [$this->offering->id => 1]);

    $guardian = Guardian::factory()->create();
    $guardian->students()->attach($this->student);
    as_($guardian->user);
    $this->get(route('wali.academic'))->assertOk()->assertSee('Setoran Juz 30');
});

test('lms pages are only for the ustadz of the offering', function () {
    as_(Teacher::factory()->create()->user);

    foreach (['materials', 'assignments', 'quizzes', 'forum'] as $page) {
        $this->get(route("ustadz.offerings.{$page}", $this->offering))->assertForbidden();
    }
});
