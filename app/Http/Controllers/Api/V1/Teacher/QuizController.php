<?php

namespace App\Http\Controllers\Api\V1\Teacher;

use App\Actions\Lms\ManageQuizzes;
use App\Http\Controllers\Api\V1\Teacher\Concerns\TeachesOffering;
use App\Http\Controllers\Controller;
use App\Models\CourseOffering;
use App\Models\Quiz;
use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use App\Models\QuizOption;
use App\Models\QuizQuestion;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Building quizzes (settings, questions, publish), the results and essay grading.
 */
#[Group('Muhadhir', weight: 11)]
class QuizController extends Controller
{
    use TeachesOffering;

    /**
     * Quizzes of a class.
     */
    public function index(Request $request, CourseOffering $offering): JsonResponse
    {
        $this->authorizeOffering($request, $offering);
        $quizzes = $offering->quizzes()->withCount(['questions', 'attempts as submitted_count' => fn ($query) => $query->whereNotNull('submitted_at')])->get();

        return response()->json(['data' => $quizzes->map(fn (Quiz $quiz) => [
            ...$this->settings($quiz),
            'questions_count' => $quiz->questions_count,
            'submitted_count' => (int) $quiz->getAttribute('submitted_count'),
        ])->values()->all()]);
    }

    /**
     * Create a quiz.
     *
     * Settings only; add questions next, then publish.
     */
    public function store(Request $request, CourseOffering $offering, ManageQuizzes $quizzes): JsonResponse
    {
        $this->authorizeOffering($request, $offering);
        $request->validate($this->rules());

        return response()->json(['data' => $this->settings($quizzes->save($offering, null, $this->input($request)))], 201);
    }

    /**
     * A quiz.
     *
     * Settings, questions with their options and the correct ones, and the submitted attempts with each
     * answer (essays to grade).
     */
    public function show(Request $request, CourseOffering $offering, Quiz $quiz): JsonResponse
    {
        $this->authorizeQuiz($request, $offering, $quiz);

        $attempts = $quiz->attempts()->with(['courseEnrollment.student.user', 'answers.question'])->whereNotNull('submitted_at')->orderByDesc('submitted_at')->get();

        return response()->json(['data' => [
            ...$this->settings($quiz),
            'questions' => $quiz->questions()->with('options')->get()->map(fn (QuizQuestion $question) => [
                'id' => $question->id,
                'type' => $question->type->value,
                'body' => $question->body,
                'points' => $question->points,
                'options' => $question->options->map(fn (QuizOption $option) => ['id' => $option->id, 'body' => $option->body, 'is_correct' => $option->is_correct])->values()->all(),
            ])->values()->all(),
            'attempts' => $attempts->map(fn (QuizAttempt $attempt) => [
                'id' => $attempt->id,
                'nim' => $attempt->courseEnrollment->student->nim,
                'name' => $attempt->courseEnrollment->student->user->name,
                'submitted_at' => $attempt->submitted_at?->toIso8601String(),
                'score' => $attempt->score,
                'max_score' => $attempt->max_score,
                'needs_manual_grading' => $attempt->needs_manual_grading,
                'answers' => $attempt->answers->map(fn (QuizAnswer $answer) => [
                    'id' => $answer->id,
                    'question_id' => $answer->quiz_question_id,
                    'selected_option_ids' => $answer->selected_option_ids,
                    'text' => $answer->text,
                    /** Null for an essay not graded yet. */
                    'points_awarded' => $answer->points_awarded,
                ])->values()->all(),
            ])->values()->all(),
        ]]);
    }

    /**
     * Change the settings.
     */
    public function update(Request $request, CourseOffering $offering, Quiz $quiz, ManageQuizzes $quizzes): JsonResponse
    {
        $this->authorizeQuiz($request, $offering, $quiz);
        $request->validate($this->rules());

        return response()->json(['data' => $this->settings($quizzes->save($offering, $quiz, $this->input($request)))]);
    }

    /**
     * Delete a quiz.
     *
     * Only while nobody has attempted it.
     */
    public function destroy(Request $request, CourseOffering $offering, Quiz $quiz, ManageQuizzes $quizzes): Response
    {
        $this->authorizeQuiz($request, $offering, $quiz);
        $quizzes->delete($quiz);

        return response()->noContent();
    }

    /**
     * Add a question.
     *
     * `type`: single_choice, multiple_choice, true_false or essay; `options`: [{body, is_correct}] for choice
     * questions (one correct for single choice and true/false).
     */
    public function storeQuestion(Request $request, CourseOffering $offering, Quiz $quiz, ManageQuizzes $quizzes): JsonResponse
    {
        $this->authorizeQuiz($request, $offering, $quiz);
        $request->validate($this->questionRules());

        $question = $quizzes->saveQuestion($quiz, null, $this->questionInput($request));

        return response()->json(['data' => ['id' => $question->id]], 201);
    }

    /**
     * Change a question.
     */
    public function updateQuestion(Request $request, CourseOffering $offering, Quiz $quiz, QuizQuestion $question, ManageQuizzes $quizzes): Response
    {
        $this->authorizeQuestion($request, $offering, $quiz, $question);
        $request->validate($this->questionRules());

        $quizzes->saveQuestion($quiz, $question, $this->questionInput($request));

        return response()->noContent();
    }

    /**
     * Delete a question.
     */
    public function destroyQuestion(Request $request, CourseOffering $offering, Quiz $quiz, QuizQuestion $question, ManageQuizzes $quizzes): Response
    {
        $this->authorizeQuestion($request, $offering, $quiz, $question);
        $quizzes->deleteQuestion($question);

        return response()->noContent();
    }

    /**
     * Publish or unpublish.
     *
     * `published: true` shows the quiz to the mahasantri (and notifies them).
     */
    public function publish(Request $request, CourseOffering $offering, Quiz $quiz, ManageQuizzes $quizzes): JsonResponse
    {
        $this->authorizeQuiz($request, $offering, $quiz);
        $request->validate(['published' => ['required', 'boolean']]);

        $quizzes->publish($quiz, $request->boolean('published'));

        return response()->json(['data' => $this->settings($quiz->refresh())]);
    }

    /**
     * Grade an essay answer.
     *
     * `points` from 0 to the question's points; the attempt's score is recalculated.
     */
    public function gradeEssay(Request $request, CourseOffering $offering, Quiz $quiz, QuizAnswer $answer, ManageQuizzes $quizzes): Response
    {
        $this->authorizeQuiz($request, $offering, $quiz);
        abort_unless($quiz->attempts()->whereKey($answer->quiz_attempt_id)->exists(), 404);
        $request->validate(['points' => ['required', 'numeric']]);

        $quizzes->gradeEssay($answer, $request->input('points'));

        return response()->noContent();
    }

    /**
     * Copy scores to the grade sheet.
     *
     * Each mahasantri's best graded attempt, as a percentage, into the linked grade component.
     */
    public function copyToGrades(Request $request, CourseOffering $offering, Quiz $quiz, ManageQuizzes $quizzes): JsonResponse
    {
        $this->authorizeQuiz($request, $offering, $quiz);

        return response()->json(['data' => ['copied' => $quizzes->copyToGradeSheet($quiz)]]);
    }

    private function authorizeQuiz(Request $request, CourseOffering $offering, Quiz $quiz): void
    {
        $this->authorizeOffering($request, $offering);
        abort_unless($quiz->course_offering_id === $offering->id, 404);
    }

    private function authorizeQuestion(Request $request, CourseOffering $offering, Quiz $quiz, QuizQuestion $question): void
    {
        $this->authorizeQuiz($request, $offering, $quiz);
        abort_unless($question->quiz_id === $quiz->id, 404);
    }

    /**
     * @return array<string, list<string>>
     */
    private function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'opens_at' => ['required', 'date'],
            'closes_at' => ['required', 'date'],
            'duration_minutes' => ['nullable', 'integer'],
            'max_attempts' => ['required', 'integer'],
            'shuffle_questions' => ['nullable', 'boolean'],
            'grade_component_id' => ['nullable', 'integer'],
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    private function questionRules(): array
    {
        return [
            'type' => ['required', 'string'],
            'body' => ['required', 'string'],
            'points' => ['required', 'integer'],
            'options' => ['nullable', 'array'],
            'options.*.body' => ['required', 'string'],
            'options.*.is_correct' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array{type: string, body: string, points: int, options: list<array{body: string, is_correct: bool}>}
     */
    private function questionInput(Request $request): array
    {
        $options = [];

        foreach ((array) $request->input('options', []) as $option) {
            if (is_array($option)) {
                $options[] = ['body' => (string) ($option['body'] ?? ''), 'is_correct' => (bool) ($option['is_correct'] ?? false)];
            }
        }

        return [
            'type' => (string) $request->input('type'),
            'body' => (string) $request->input('body'),
            'points' => $request->integer('points'),
            'options' => $options,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function input(Request $request): array
    {
        return [
            'title' => $request->input('title'),
            'description' => $request->input('description') ?: null,
            'opens_at' => $request->input('opens_at'),
            'closes_at' => $request->input('closes_at'),
            'duration_minutes' => $request->integer('duration_minutes') ?: null,
            'max_attempts' => $request->integer('max_attempts'),
            'shuffle_questions' => $request->boolean('shuffle_questions'),
            'grade_component_id' => $request->integer('grade_component_id') ?: null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function settings(Quiz $quiz): array
    {
        return [
            'id' => $quiz->id,
            'title' => $quiz->title,
            'description' => $quiz->description,
            'opens_at' => $quiz->opens_at->toIso8601String(),
            'closes_at' => $quiz->closes_at->toIso8601String(),
            'duration_minutes' => $quiz->duration_minutes,
            'max_attempts' => $quiz->max_attempts,
            'shuffle_questions' => $quiz->shuffle_questions,
            'grade_component_id' => $quiz->grade_component_id,
            'published' => $quiz->isPublished(),
        ];
    }
}
