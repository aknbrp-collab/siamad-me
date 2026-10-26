<?php

namespace App\Actions\Lms;

use App\Actions\Teaching\ManageGrades;
use App\Enums\QuestionType;
use App\Models\CourseEnrollment;
use App\Models\CourseOffering;
use App\Models\Quiz;
use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use App\Support\Notify;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Quizzes: authoring (locked once anyone has attempted), timed attempts with
 * a server-side deadline, auto-grading of choice questions and manual
 * grading of essays.
 */
class ManageQuizzes
{
    public function __construct(private readonly ManageGrades $grades) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function save(CourseOffering $offering, ?Quiz $quiz, array $input): Quiz
    {
        $offering->loadMissing('semester')->semester->assertOpen();

        $data = Validator::make($input, [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'opens_at' => ['required', 'date'],
            'closes_at' => ['required', 'date', 'after:opens_at'],
            'duration_minutes' => ['nullable', 'integer', 'between:1,600'],
            'max_attempts' => ['required', 'integer', 'between:1,10'],
            'shuffle_questions' => ['boolean'],
            'grade_component_id' => ['nullable', 'integer', Rule::exists('grade_components', 'id')->where('course_offering_id', $offering->id)],
        ])->validate();

        if ($quiz !== null) {
            $quiz->update($data);

            return $quiz;
        }

        return $offering->quizzes()->create($data);
    }

    public function delete(Quiz $quiz): void
    {
        $this->ensureEditable($quiz);

        $quiz->delete();
    }

    /**
     * @param  array{type: string, body: string, points: int|string|null, options?: list<array{body: string, is_correct: bool}>}  $input
     */
    public function saveQuestion(Quiz $quiz, ?QuizQuestion $question, array $input): QuizQuestion
    {
        $this->ensureEditable($quiz);

        $data = Validator::make($input, [
            'type' => ['required', Rule::enum(QuestionType::class)],
            'body' => ['required', 'string', 'max:5000'],
            'points' => ['required', 'integer', 'between:1,100'],
            'options' => ['array'],
            'options.*.body' => ['required', 'string', 'max:1000'],
            'options.*.is_correct' => ['boolean'],
        ], [
            'options.*.body.required' => __('Every option needs text.'),
        ])->validate();

        $type = QuestionType::from($data['type']);
        $options = $type->hasOptions() ? array_values($data['options'] ?? []) : [];
        $correct = count(array_filter($options, fn (array $option) => (bool) ($option['is_correct'] ?? false)));

        if ($type->hasOptions() && count($options) < 2) {
            $this->fail('options', __('Add at least two options.'));
        }

        if (in_array($type, [QuestionType::SingleChoice, QuestionType::TrueFalse], true) && $correct !== 1) {
            $this->fail('options', __('Mark exactly one correct option.'));
        }

        if ($type === QuestionType::MultipleChoice && $correct < 1) {
            $this->fail('options', __('Mark at least one correct option.'));
        }

        return DB::transaction(function () use ($quiz, $question, $data, $type, $options) {
            $attributes = ['type' => $type, 'body' => $data['body'], 'points' => (int) $data['points']];

            if ($question !== null) {
                $question->update($attributes);
                $question->options()->delete();
            } else {
                $question = $quiz->questions()->create([...$attributes, 'position' => (int) $quiz->questions()->max('position') + 1]);
            }

            foreach ($options as $position => $option) {
                $question->options()->create([
                    'body' => $option['body'],
                    'is_correct' => (bool) ($option['is_correct'] ?? false),
                    'position' => $position,
                ]);
            }

            return $question;
        });
    }

    public function deleteQuestion(QuizQuestion $question): void
    {
        $this->ensureEditable($question->loadMissing('quiz')->quiz);

        $question->delete();
    }

    public function publish(Quiz $quiz, bool $publish = true): void
    {
        $quiz->loadMissing('courseOffering.semester')->courseOffering->semester->assertOpen();

        if (! $publish && $quiz->attempts()->exists()) {
            $this->fail('quiz', __('Mahasantri have already started this quiz; it cannot be unpublished.'));
        }

        if ($publish && ! $quiz->questions()->exists()) {
            $this->fail('quiz', __('Add at least one question before publishing.'));
        }

        $wasPublished = $quiz->published_at !== null;
        $quiz->update(['published_at' => $publish ? now() : null]);

        if ($publish && ! $wasPublished) {
            $offering = $quiz->courseOffering;

            Notify::send(
                $offering->enrollments()->with('student.user')->get()->map(fn ($enrollment) => $enrollment->student->user),
                'work',
                __('New quiz: :title', ['title' => $quiz->title]),
                __(':subject · open until :date', ['subject' => $offering->subject_name, 'date' => $quiz->closes_at->translatedFormat('j M Y H:i')]),
                route('mahasantri.class', [$offering, 'tab' => 'quizzes']),
                'question-mark-circle',
            );
        }
    }

    /**
     * Start an attempt, or return the one already in progress.
     */
    public function start(Quiz $quiz, CourseEnrollment $enrollment): QuizAttempt
    {
        $quiz->loadMissing('courseOffering.semester')->courseOffering->semester->assertOpen();

        if ($enrollment->course_offering_id !== $quiz->course_offering_id) {
            $this->fail('quiz', __('You are not enrolled in this class.'));
        }

        $this->closeExpired($quiz, $enrollment);

        $current = $quiz->attempts()->where('course_enrollment_id', $enrollment->id)->whereNull('submitted_at')->first();

        if ($current !== null) {
            return $current;
        }

        if (! $quiz->isOpen()) {
            $this->fail('quiz', __('This quiz is not open now.'));
        }

        if ($quiz->attempts()->where('course_enrollment_id', $enrollment->id)->count() >= $quiz->max_attempts) {
            $this->fail('quiz', __('You have used all your attempts.'));
        }

        $questions = $quiz->questions()->get();
        $order = $questions->pluck('id')->all();

        if ($quiz->shuffle_questions) {
            shuffle($order);
        }

        $deadline = $quiz->duration_minutes
            ? now()->addMinutes($quiz->duration_minutes)->min($quiz->closes_at)
            : $quiz->closes_at;

        return $quiz->attempts()->create([
            'course_enrollment_id' => $enrollment->id,
            'started_at' => now(),
            'deadline_at' => $deadline,
            'max_score' => (int) $questions->sum('points'),
            'question_order' => $order,
        ]);
    }

    /**
     * Save one answer while the attempt is running.
     *
     * @param  list<int>|int|string|null  $value  Option id(s) for choice questions, text for essays.
     */
    public function answer(QuizAttempt $attempt, QuizQuestion $question, mixed $value): void
    {
        if (! $attempt->isInProgress()) {
            $this->fail('answer', __('Time is up; your answers were submitted.'));
        }

        if (! in_array($question->id, $attempt->question_order, true)) {
            $this->fail('answer', __('This question is not part of your quiz.'));
        }

        $attributes = ['selected_option_ids' => null, 'text' => null];

        if ($question->type->hasOptions()) {
            $selected = array_values(array_unique(array_map('intval', (array) $value)));
            $valid = $question->options()->pluck('id')->all();

            if (array_diff($selected, $valid) !== [] || ($question->type !== QuestionType::MultipleChoice && count($selected) > 1)) {
                $this->fail('answer', __('Choose from the given options.'));
            }

            $attributes['selected_option_ids'] = $selected;
        } else {
            // Essays take text only; anything else is stored as empty.
            $attributes['text'] = is_string($value) ? mb_substr($value, 0, 20000) : null;
        }

        $attempt->answers()->updateOrCreate(['quiz_question_id' => $question->id], $attributes);
    }

    /**
     * Hand in: choice questions are graded at once, essays wait for the ustadz.
     */
    public function submit(QuizAttempt $attempt): void
    {
        if ($attempt->submitted_at !== null) {
            return;
        }

        DB::transaction(function () use ($attempt) {
            $questions = $attempt->quiz->questions()->with('options')->get()->keyBy('id');
            $answers = $attempt->answers()->get()->keyBy('quiz_question_id');

            foreach ($questions as $question) {
                if ($question->type === QuestionType::Essay) {
                    continue;
                }

                $answer = $answers->get($question->id) ?? $attempt->answers()->create(['quiz_question_id' => $question->id, 'selected_option_ids' => []]);
                $correct = $question->options->where('is_correct', true)->pluck('id')->sort()->values()->all();
                $chosen = collect($answer->selected_option_ids ?? [])->sort()->values()->all();

                $answer->update(['points_awarded' => $chosen === $correct ? $question->points : 0]);
            }

            $attempt->update(['submitted_at' => now()->min($attempt->deadline_at)]);
            $this->rescore($attempt);
        });
    }

    /**
     * Submit an attempt whose time ran out.
     */
    public function closeExpired(Quiz $quiz, CourseEnrollment $enrollment): void
    {
        $quiz->attempts()
            ->where('course_enrollment_id', $enrollment->id)
            ->whereNull('submitted_at')
            ->where('deadline_at', '<=', now())
            ->get()
            ->each(fn (QuizAttempt $attempt) => $this->submit($attempt));
    }

    public function gradeEssay(QuizAnswer $answer, mixed $points): void
    {
        $answer->loadMissing('question');
        $attempt = QuizAttempt::query()->findOrFail($answer->quiz_attempt_id);
        $attempt->loadMissing('quiz.courseOffering.semester');
        $attempt->quiz->courseOffering->semester->assertOpen();

        if ($attempt->submitted_at === null) {
            $this->fail('points', __('The attempt is still in progress.'));
        }

        $value = is_numeric($points) ? round((float) $points, 2) : null;

        if ($value === null || $value < 0 || $value > $answer->question->points) {
            $this->fail('points', __('Give between 0 and :max points.', ['max' => $answer->question->points]));
        }

        DB::transaction(function () use ($answer, $attempt, $value) {
            $answer->update(['points_awarded' => $value]);
            $this->rescore($attempt);
        });
    }

    /**
     * Copy each mahasantri's best graded attempt (as a percentage) into the
     * linked grade component.
     */
    public function copyToGradeSheet(Quiz $quiz): int
    {
        if ($quiz->grade_component_id === null) {
            $this->fail('quiz', __('Link this quiz to a grade component first.'));
        }

        $scores = [];

        foreach ($quiz->attempts()->whereNotNull('submitted_at')->where('needs_manual_grading', false)->get() as $attempt) {
            $percent = $attempt->percent();

            if ($percent !== null && $percent > ($scores[$attempt->course_enrollment_id][$quiz->grade_component_id] ?? -1)) {
                $scores[$attempt->course_enrollment_id] = [$quiz->grade_component_id => $percent];
            }
        }

        $this->grades->saveScores($quiz->loadMissing('courseOffering')->courseOffering, $scores);

        return count($scores);
    }

    private function rescore(QuizAttempt $attempt): void
    {
        $answers = $attempt->answers()->with('question')->get();
        $essayIds = QuizQuestion::query()->whereIn('id', $attempt->question_order)->where('type', QuestionType::Essay)->pluck('id');

        $ungradedEssay = $essayIds->contains(fn (int $id) => $answers->firstWhere('quiz_question_id', $id)?->points_awarded === null);

        $attempt->update([
            'score' => $answers->sum(fn (QuizAnswer $answer) => (float) $answer->points_awarded),
            'needs_manual_grading' => $ungradedEssay,
        ]);
    }

    private function ensureEditable(Quiz $quiz): void
    {
        $quiz->loadMissing('courseOffering.semester')->courseOffering->semester->assertOpen();

        if ($quiz->attempts()->exists()) {
            $this->fail('quiz', __('Mahasantri have already started this quiz; its questions are locked.'));
        }
    }

    private function fail(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
