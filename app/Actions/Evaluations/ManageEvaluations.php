<?php

namespace App\Actions\Evaluations;

use App\Models\CourseEnrollment;
use App\Models\CourseOffering;
use App\Models\EvaluationPeriod;
use App\Models\EvaluationQuestion;
use App\Models\EvaluationScore;
use App\Models\Semester;
use App\Models\Student;
use App\Models\Teacher;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Muhadhir evaluation (v6 D35). The Admin sets the period and the questions
 * of a semester; each mahasantri answers once per class kuliah they follow,
 * anonymously; results show only once MIN_RESPONSES mahasantri answered.
 */
class ManageEvaluations
{
    public const MIN_RESPONSES = 3;

    public const DEFAULT_PROMPT = 'Saran untuk muhadhir';

    /**
     * Questions for a semester without any and nothing to copy from.
     *
     * @var list<array{0: string, 1: string}>
     */
    public const DEFAULT_QUESTIONS = [
        ['Penguasaan materi', 'Menguasai materi kuliah dengan baik.'],
        ['Penguasaan materi', 'Menjelaskan materi dengan jelas dan mudah dipahami.'],
        ['Penguasaan materi', 'Mengaitkan materi dengan kitab rujukan dan contoh yang relevan.'],
        ['Kedisiplinan', 'Hadir dan memulai kuliah tepat waktu.'],
        ['Kedisiplinan', 'Menyampaikan RPS, tugas, dan penilaian sesuai rencana.'],
        ['Interaksi', 'Memberi kesempatan bertanya dan menanggapi dengan baik.'],
        ['Interaksi', 'Bersikap adil dan menghargai mahasantri.'],
        ['Penilaian', 'Menilai tugas dan ujian secara objektif dan memberi umpan balik.'],
    ];

    /**
     * @param  array<string, mixed>  $input  opens_on, closes_on, comment_prompt, required_for_khs
     */
    public function savePeriod(Semester $semester, array $input): EvaluationPeriod
    {
        $data = Validator::make($input, [
            'opens_on' => ['required', 'date'],
            'closes_on' => ['required', 'date', 'after_or_equal:opens_on'],
            'comment_prompt' => ['required', 'string', 'max:255'],
            'required_for_khs' => ['boolean'],
        ], [], [
            'opens_on' => __('opens on'), 'closes_on' => __('closes on'), 'comment_prompt' => __('closing question'),
        ])->validate();

        return EvaluationPeriod::query()->updateOrCreate(['semester_id' => $semester->id], [
            'opens_on' => $data['opens_on'],
            'closes_on' => $data['closes_on'],
            'comment_prompt' => trim($data['comment_prompt']),
            'required_for_khs' => (bool) ($data['required_for_khs'] ?? false),
        ]);
    }

    /**
     * Fill an empty semester with the questions of the latest earlier semester
     * that has some (active ones only), or with the defaults.
     */
    public function prepareQuestions(Semester $semester): int
    {
        if (EvaluationQuestion::query()->where('semester_id', $semester->id)->exists()) {
            $this->fail('questions', __('This semester already has questions.'));
        }

        $previous = Semester::query()->with('academicYear')->whereIn('id', EvaluationQuestion::query()->select('semester_id'))->get()
            ->filter(fn (Semester $other) => $other->chronology() < $semester->chronology())
            ->sortByDesc(fn (Semester $other) => $other->chronology())
            ->first();

        $source = $previous === null
            ? collect(self::DEFAULT_QUESTIONS)->map(fn (array $row) => ['group' => $row[0], 'question' => $row[1]])
            : EvaluationQuestion::query()->where('semester_id', $previous->id)->where('is_active', true)->orderBy('position')->orderBy('id')
                ->get()->map(fn (EvaluationQuestion $question) => ['group' => $question->group, 'question' => $question->question]);

        DB::transaction(fn () => $source->values()->each(fn (array $row, int $index) => EvaluationQuestion::create([
            ...$row, 'semester_id' => $semester->id, 'position' => $index + 1,
        ])));

        return $source->count();
    }

    public function addQuestion(Semester $semester, string $group, string $question): EvaluationQuestion
    {
        $data = $this->validateQuestion($group, $question);

        return EvaluationQuestion::create([
            ...$data,
            'semester_id' => $semester->id,
            'position' => (int) EvaluationQuestion::query()->where('semester_id', $semester->id)->max('position') + 1,
        ]);
    }

    /**
     * Change the wording, only while nobody has answered it (the results must
     * keep meaning what was asked). Afterwards: deactivate and add a new one.
     */
    public function updateQuestion(EvaluationQuestion $question, string $group, string $text): void
    {
        if ($question->scores()->exists()) {
            $this->fail('question', __('Mahasantri already answered this question. Turn it off and add a new one instead.'));
        }

        $question->update($this->validateQuestion($group, $text));
    }

    public function setActive(EvaluationQuestion $question, bool $active): void
    {
        $question->update(['is_active' => $active]);
    }

    public function move(EvaluationQuestion $question, int $direction): void
    {
        $siblings = EvaluationQuestion::query()->where('semester_id', $question->semester_id)->orderBy('position')->orderBy('id')->get()->values();
        $index = $siblings->search(fn (EvaluationQuestion $other) => $other->id === $question->id);
        $target = $siblings->get($index + $direction);

        if ($index === false || $target === null) {
            return;
        }

        DB::transaction(function () use ($siblings, $index, $target, $question) {
            $siblings->each(fn (EvaluationQuestion $other, int $position) => $other->position !== $position + 1 ? $other->update(['position' => $position + 1]) : null);
            $current = $index + 1;
            $question->refresh()->update(['position' => $target->refresh()->position]);
            $target->update(['position' => $current]);
        });
    }

    /**
     * The open period of a semester, if any.
     */
    public static function openPeriod(int $semesterId): ?EvaluationPeriod
    {
        $period = EvaluationPeriod::query()->where('semester_id', $semesterId)->first();

        return $period?->isOpen() ? $period : null;
    }

    /**
     * The student's classes in semesters whose evaluation is open, with
     * whether each was answered.
     *
     * @return Collection<int, array{offering: CourseOffering, period: EvaluationPeriod, done: bool}>
     */
    public function forStudent(Student $student): Collection
    {
        $periods = EvaluationPeriod::query()
            ->whereDate('opens_on', '<=', today())->whereDate('closes_on', '>=', today())
            ->whereIn('semester_id', EvaluationQuestion::query()->where('is_active', true)->select('semester_id'))
            ->get()->keyBy('semester_id');

        if ($periods->isEmpty()) {
            return collect();
        }

        $offerings = CourseOffering::query()
            ->with('teacher.user')
            ->whereIn('semester_id', $periods->keys())
            ->whereIn('id', CourseEnrollment::query()->where('student_id', $student->id)->select('course_offering_id'))
            ->orderBy('subject_name')
            ->get();

        $done = DB::table('evaluation_submissions')->where('student_id', $student->id)->whereIn('course_offering_id', $offerings->modelKeys())->pluck('course_offering_id')->all();

        $rows = collect();

        foreach ($offerings as $offering) {
            $period = $periods->get($offering->semester_id);

            if ($period !== null) {
                $rows->push(['offering' => $offering, 'period' => $period, 'done' => in_array($offering->id, $done, true)]);
            }
        }

        return $rows;
    }

    /**
     * How many classes the student still has to evaluate (for reminders and the KHS rule).
     */
    public function pendingCount(Student $student, ?int $semesterId = null): int
    {
        return $this->forStudent($student)
            ->filter(fn (array $row) => ! $row['done'] && ($semesterId === null || $row['offering']->semester_id === $semesterId))
            ->count();
    }

    /**
     * Whether the KHS of the semester waits for the student's evaluation.
     */
    public function blocksKhs(Student $student, int $semesterId): bool
    {
        $period = self::openPeriod($semesterId);

        return $period !== null && $period->required_for_khs && $this->pendingCount($student, $semesterId) > 0;
    }

    /**
     * @param  array<int|string, mixed>  $scores  question id => 1..5
     */
    public function submit(Student $student, CourseOffering $offering, array $scores, ?string $comment): void
    {
        $period = self::openPeriod($offering->semester_id);

        if ($period === null) {
            $this->fail('scores', __('The evaluation of this semester is not open.'));
        }

        if (! CourseEnrollment::query()->where('course_offering_id', $offering->id)->where('student_id', $student->id)->exists()) {
            $this->fail('scores', __('You do not follow this class.'));
        }

        $questions = EvaluationQuestion::query()->where('semester_id', $offering->semester_id)->where('is_active', true)->pluck('id');
        $missing = $questions->reject(fn (int $id) => in_array((int) ($scores[$id] ?? 0), [1, 2, 3, 4, 5], true));

        if ($questions->isEmpty() || $missing->isNotEmpty()) {
            $this->fail('scores', __('Give a score from 1 to 5 to every statement.'));
        }

        $comment = trim((string) $comment);
        Validator::make(['comment' => $comment], ['comment' => ['nullable', 'string', 'max:2000']], [], ['comment' => __('comment')])->validate();

        try {
            DB::transaction(function () use ($student, $offering, $questions, $scores, $comment) {
                DB::table('evaluation_submissions')->insert(['student_id' => $student->id, 'course_offering_id' => $offering->id]);

                foreach ($questions as $id) {
                    $key = ['course_offering_id' => $offering->id, 'evaluation_question_id' => $id, 'score' => (int) $scores[$id]];
                    EvaluationScore::query()->insertOrIgnore([...$key, 'count' => 0]);
                    EvaluationScore::query()->where($key)->increment('count');
                }

                if ($comment !== '') {
                    DB::table('evaluation_comments')->insert(['course_offering_id' => $offering->id, 'body' => $comment]);
                }
            });
        } catch (UniqueConstraintViolationException) {
            $this->fail('scores', __('You already evaluated this class.'));
        }
    }

    /**
     * Results per class kuliah of a semester (optionally one muhadhir's).
     * Scores and comments are left out while fewer than MIN_RESPONSES answered.
     *
     * @return Collection<int, array{offering: CourseOffering, responses: int, enrolled: int, shown: bool, average: float|null, questions: list<array{question: EvaluationQuestion, average: float|null, answers: int, distribution: non-empty-array<int, int>}>, comments: list<string>}>
     */
    public function results(Semester $semester, ?Teacher $teacher = null): Collection
    {
        $offerings = CourseOffering::query()
            ->with('teacher.user')
            ->where('semester_id', $semester->id)
            ->when($teacher !== null, fn ($query) => $query->where('teacher_id', $teacher?->id))
            ->orderBy('subject_name')->orderBy('class_name')
            ->get();
        $ids = $offerings->modelKeys();

        $responses = DB::table('evaluation_submissions')->whereIn('course_offering_id', $ids)
            ->groupBy('course_offering_id')->selectRaw('course_offering_id, count(*) as total')->pluck('total', 'course_offering_id');
        $enrolled = CourseEnrollment::query()->whereIn('course_offering_id', $ids)
            ->groupBy('course_offering_id')->selectRaw('course_offering_id, count(*) as total')->pluck('total', 'course_offering_id');
        // offering id => question id => score => count
        $counts = [];
        foreach (EvaluationScore::query()->whereIn('course_offering_id', $ids)->get() as $score) {
            $counts[$score->course_offering_id][$score->evaluation_question_id][$score->score] = $score->count;
        }

        // offering id => comments, in random order (nothing to line up with the submissions).
        $comments = [];
        foreach (DB::table('evaluation_comments')->whereIn('course_offering_id', $ids)->inRandomOrder()->get(['course_offering_id', 'body']) as $row) {
            $body = data_get($row, 'body');
            if (is_string($body)) {
                $comments[(int) data_get($row, 'course_offering_id')][] = $body;
            }
        }

        $questions = EvaluationQuestion::query()->where('semester_id', $semester->id)->orderBy('position')->orderBy('id')->get();

        return $offerings->map(function (CourseOffering $offering) use ($responses, $enrolled, $counts, $comments, $questions) {
            $count = (int) ($responses[$offering->id] ?? 0);
            $shown = $count >= self::MIN_RESPONSES;
            $byQuestion = $counts[$offering->id] ?? [];
            $rows = [];

            foreach ($questions as $question) {
                if (! $question->is_active && ! isset($byQuestion[$question->id])) {
                    continue;
                }

                $distribution = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
                $answers = 0;
                $total = 0;

                foreach ($byQuestion[$question->id] ?? [] as $score => $times) {
                    $distribution[$score] = $times;
                    $answers += $times;
                    $total += $score * $times;
                }

                $rows[] = [
                    'question' => $question,
                    'average' => $shown && $answers > 0 ? round($total / $answers, 2) : null,
                    'answers' => $answers,
                    'distribution' => $shown ? $distribution : [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0],
                ];
            }

            $averages = array_values(array_filter(array_column($rows, 'average'), fn ($value) => $value !== null));

            return [
                'offering' => $offering,
                'responses' => $count,
                'enrolled' => (int) ($enrolled[$offering->id] ?? 0),
                'shown' => $shown,
                'average' => $averages === [] ? null : round(array_sum($averages) / count($averages), 2),
                'questions' => $rows,
                'comments' => $shown ? ($comments[$offering->id] ?? []) : [],
            ];
        })->values();
    }

    /**
     * @return array{group: string, question: string}
     */
    private function validateQuestion(string $group, string $question): array
    {
        $data = ['group' => trim($group), 'question' => trim($question)];

        Validator::make($data, [
            'group' => ['required', 'string', 'max:100'],
            'question' => ['required', 'string', 'max:500'],
        ], [], ['group' => __('group'), 'question' => __('statement')])->validate();

        return $data;
    }

    private function fail(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
