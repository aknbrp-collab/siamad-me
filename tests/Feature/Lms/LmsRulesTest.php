<?php

use App\Actions\Enrollment\ManageClassEnrollments;
use App\Actions\Enrollment\ManageCourseOfferings;
use App\Actions\Lms\ManageAssignments;
use App\Actions\Lms\ManageForum;
use App\Actions\Lms\ManageQuizzes;
use App\Actions\Teaching\ManageGrades;
use App\Models\ClassGroup;
use App\Models\CourseEnrollment;
use App\Models\GradeScore;
use App\Models\Quiz;
use App\Models\Semester;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    Storage::fake('local');
    $this->semester = Semester::factory()->active()->create();
    $this->classGroup = ClassGroup::factory()->create();
    $this->teacher = Teacher::factory()->create();
    $this->offering = app(ManageCourseOfferings::class)->save($this->semester, null, [
        'subject_id' => Subject::factory()->create()->id, 'class_group_id' => $this->classGroup->id, 'teacher_id' => $this->teacher->id,
    ]);
    $this->student = Student::factory()->create();
    app(ManageClassEnrollments::class)->enroll($this->semester, $this->classGroup, [$this->student->id]);
    $this->enrollment = CourseEnrollment::query()->sole();
});

test('submissions: on time, late, deadline, and locked once graded', function () {
    $assignments = app(ManageAssignments::class);
    $strict = $assignments->save($this->offering, null, ['title' => 'Ringkasan', 'due_at' => now()->subDay(), 'max_score' => 50, 'allow_late' => false]);
    $lenient = $assignments->save($this->offering, null, ['title' => 'Makalah', 'due_at' => now()->subDay(), 'max_score' => 100, 'allow_late' => true]);

    expect(fn () => $assignments->submit($strict, $this->enrollment, ['text' => 'x']))->toThrow(ValidationException::class);

    $submission = $assignments->submit($lenient, $this->enrollment, ['file' => UploadedFile::fake()->create('makalah.pdf', 100, 'application/pdf')]);
    expect($submission->is_late)->toBeTrue();
    Storage::disk('local')->assertExists($submission->file_path);

    expect(fn () => $assignments->grade($submission, 120, null, $this->teacher->user))->toThrow(ValidationException::class);
    $assignments->grade($submission, '85,5', 'Bagus', $this->teacher->user);

    expect($submission->fresh()->score)->toBe('85.50')
        ->and(fn () => $assignments->submit($lenient, $this->enrollment, ['text' => 'revisi']))->toThrow(ValidationException::class);
});

test('an empty submission is refused', function () {
    $assignment = app(ManageAssignments::class)->save($this->offering, null, ['title' => 'T', 'due_at' => now()->addDay(), 'max_score' => 100]);

    expect(fn () => app(ManageAssignments::class)->submit($assignment, $this->enrollment, ['text' => '  ']))->toThrow(ValidationException::class);
});

test('assignment scores are copied into the grade sheet as a percentage', function () {
    app(ManageGrades::class)->saveComponents($this->offering, [['name' => 'Tugas', 'weight' => 30], ['name' => 'UAS', 'weight' => 70]]);
    $component = $this->offering->components()->where('name', 'Tugas')->sole();
    $assignments = app(ManageAssignments::class);
    $assignment = $assignments->save($this->offering, null, ['title' => 'T', 'due_at' => now()->addDay(), 'max_score' => 50, 'grade_component_id' => $component->id]);
    $assignments->grade($assignments->submit($assignment, $this->enrollment, ['text' => 'jawaban']), 40, null, $this->teacher->user);

    expect($assignments->copyToGradeSheet($assignment))->toBe(1)
        ->and(GradeScore::query()->sole()->score)->toBe('80.00');
});

function quizWithQuestions(): Quiz
{
    $quizzes = app(ManageQuizzes::class);
    $quiz = $quizzes->save(test()->offering, null, [
        'title' => 'Kuis Thaharah', 'opens_at' => now()->subHour(), 'closes_at' => now()->addDay(),
        'duration_minutes' => 30, 'max_attempts' => 2,
    ]);
    $quizzes->saveQuestion($quiz, null, ['type' => 'single_choice', 'body' => 'Air mutlak adalah…', 'points' => 2, 'options' => [
        ['body' => 'Air suci mensucikan', 'is_correct' => true], ['body' => 'Air musta\'mal', 'is_correct' => false],
    ]]);
    $quizzes->saveQuestion($quiz, null, ['type' => 'multiple_choice', 'body' => 'Rukun wudhu…', 'points' => 2, 'options' => [
        ['body' => 'Niat', 'is_correct' => true], ['body' => 'Membasuh muka', 'is_correct' => true], ['body' => 'Berkumur', 'is_correct' => false],
    ]]);
    $quizzes->saveQuestion($quiz, null, ['type' => 'essay', 'body' => 'Jelaskan tayammum.', 'points' => 6]);

    return $quiz;
}

test('quizzes: publish rules, auto-grading, essay grading, attempt limit', function () {
    $quizzes = app(ManageQuizzes::class);
    $quiz = quizWithQuestions();

    expect(fn () => $quizzes->start($quiz, $this->enrollment))->toThrow(ValidationException::class); // not published
    $quizzes->publish($quiz);

    $attempt = $quizzes->start($quiz, $this->enrollment);
    expect($attempt->max_score)->toBe(10)
        ->and($attempt->deadline_at->diffInMinutes($attempt->started_at, true))->toBeGreaterThan(29);

    // Starting again returns the attempt in progress.
    expect($quizzes->start($quiz, $this->enrollment)->id)->toBe($attempt->id);

    [$q1, $q2, $q3] = $quiz->questions()->with('options')->get()->all();
    $quizzes->answer($attempt, $q1, $q1->options[0]->id);
    $quizzes->answer($attempt, $q2, [$q2->options[0]->id]); // incomplete → 0 points
    $quizzes->answer($attempt, $q3, 'Bersuci dengan debu.');

    expect(fn () => $quizzes->answer($attempt, $q1, [$q1->options[0]->id, $q1->options[1]->id]))->toThrow(ValidationException::class);

    $quizzes->submit($attempt);
    $attempt->refresh();

    expect($attempt->score)->toBe('2.00')->and($attempt->needs_manual_grading)->toBeTrue()->and($attempt->percent())->toBeNull();

    // Questions are locked once someone attempted.
    expect(fn () => $quizzes->deleteQuestion($q3))->toThrow(ValidationException::class);

    $essay = $attempt->answers()->where('quiz_question_id', $q3->id)->sole();
    expect(fn () => $quizzes->gradeEssay($essay, 7))->toThrow(ValidationException::class);
    $quizzes->gradeEssay($essay, 5);

    expect($attempt->fresh())->score->toBe('7.00')->needs_manual_grading->toBeFalse()
        ->and($attempt->fresh()->percent())->toBe(70.0);

    $quizzes->submit($quizzes->start($quiz, $this->enrollment));
    expect(fn () => $quizzes->start($quiz, $this->enrollment))->toThrow(ValidationException::class);
});

test('an attempt whose time ran out is submitted automatically and refuses answers', function () {
    $quizzes = app(ManageQuizzes::class);
    $quiz = quizWithQuestions();
    $quizzes->publish($quiz);
    $attempt = $quizzes->start($quiz, $this->enrollment);

    $this->travel(31)->minutes();

    expect(fn () => $quizzes->answer($attempt, $quiz->questions()->first(), null))->toThrow(ValidationException::class);

    $quizzes->closeExpired($quiz, $this->enrollment);
    expect($attempt->fresh()->submitted_at)->not->toBeNull();
});

test('choice questions need a correct answer', function () {
    $quizzes = app(ManageQuizzes::class);
    $quiz = $quizzes->save($this->offering, null, ['title' => 'K', 'opens_at' => now(), 'closes_at' => now()->addDay(), 'max_attempts' => 1]);

    expect(fn () => $quizzes->saveQuestion($quiz, null, ['type' => 'single_choice', 'body' => 'Q', 'points' => 1, 'options' => [
        ['body' => 'A', 'is_correct' => false], ['body' => 'B', 'is_correct' => false],
    ]]))->toThrow(ValidationException::class);
});

test('forum: participants post, the ustadz moderates, outsiders are refused', function () {
    $forum = app(ManageForum::class);
    $thread = $forum->startThread($this->offering, $this->student->user, 'Tanya bab najis', 'Apa hukum…?');
    $reply = $forum->reply($thread, $this->teacher->user, 'Jawabannya…');

    $forum->toggleLock($thread, $this->teacher->user);
    expect(fn () => $forum->reply($thread->fresh(), $this->student->user, 'lagi'))->toThrow(ValidationException::class)
        ->and(fn () => $forum->toggleLock($thread, $this->student->user))->toThrow(ValidationException::class)
        ->and(fn () => $forum->deletePost($reply, $this->student->user))->toThrow(ValidationException::class);

    $outsider = Student::factory()->create();
    expect(fn () => $forum->startThread($this->offering, $outsider->user, 'x', 'y'))
        ->toThrow(HttpException::class);
});

test('closed semesters are read-only for the classroom too', function () {
    $assignment = app(ManageAssignments::class)->save($this->offering, null, ['title' => 'T', 'due_at' => now()->addDay(), 'max_score' => 100]);
    $this->semester->update(['status' => 'closed']);

    expect(fn () => app(ManageAssignments::class)->submit($assignment->fresh(), $this->enrollment, ['text' => 'x']))->toThrow(ValidationException::class)
        ->and(fn () => app(ManageForum::class)->startThread($this->offering->fresh(), $this->student->user, 'x', 'y'))->toThrow(ValidationException::class);
});

test('students with submissions cannot be moved out of the class', function () {
    $assignment = app(ManageAssignments::class)->save($this->offering, null, ['title' => 'T', 'due_at' => now()->addDay(), 'max_score' => 100]);
    app(ManageAssignments::class)->submit($assignment, $this->enrollment, ['text' => 'jawaban']);

    expect(fn () => app(ManageClassEnrollments::class)->enroll($this->semester, ClassGroup::factory()->create(), [$this->student->id]))
        ->toThrow(ValidationException::class);
});
