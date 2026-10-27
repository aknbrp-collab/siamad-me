<?php

namespace App\Imports\History;

use App\Actions\Teaching\ManageGrades;
use App\Models\ClassEnrollment;
use App\Models\CourseOffering;
use App\Models\GradeScale;
use App\Models\Semester;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Final grades of a past semester. The mahasantri must be placed in a class
 * that semester; the course offering (subject × that class) is created when
 * missing, which needs the muhadhir. Grades are stored as final, with the
 * letter from the archive when given (its point from the grade scale), or
 * resolved from the score. A grade that is already final is not touched.
 */
class GradeHistory implements HistoryImporter
{
    /** @var Collection<string, GradeScale>|null bands by letter */
    private ?Collection $scale = null;

    /**
     * Offerings an earlier valid row of the same file will create: "subject|class" => teacher id.
     * Later rows for the same subject and class need no teacher_nidm.
     *
     * @var array<string, int>
     */
    private array $planned = [];

    public function __construct(private readonly ManageGrades $grades) {}

    public function needsSemester(): bool
    {
        return true;
    }

    public function label(): string
    {
        return __('Final grades');
    }

    public function columns(): array
    {
        return [
            'nim' => __('Required. The mahasantri must be placed in a class this semester.'),
            'subject_code' => __('Required. Code from the Subjects page.'),
            'score' => __('Score 0–100. Score or letter is required.'),
            'letter' => __('Optional: the letter from the archive (e.g. B+). Without it, the letter follows the grade scale.'),
            'teacher_nidm' => __('Required only when SIAKAD has no class offering for this subject and class yet.'),
        ];
    }

    public function example(): array
    {
        return [
            'nim' => '2025110001',
            'subject_code' => Subject::query()->value('code') ?? 'FQH101',
            'score' => '85',
            'letter' => '',
            'teacher_nidm' => Teacher::query()->value('nidm') ?? '2026010001',
        ];
    }

    public function key(array $row): string
    {
        return Str::lower(trim($row['nim'] ?? '').'|'.trim($row['subject_code'] ?? ''));
    }

    public function check(?Semester $semester, array $row): array
    {
        if ($semester === null) {
            throw new LogicException('A semester is required.');
        }

        $errors = [];
        $nim = trim($row['nim'] ?? '');
        $code = trim($row['subject_code'] ?? '');
        $rawScore = trim($row['score'] ?? '');
        $letter = Str::upper(trim($row['letter'] ?? ''));
        $nidm = trim($row['teacher_nidm'] ?? '');

        $placement = $nim === '' ? null : ClassEnrollment::query()
            ->where('semester_id', $semester->id)
            ->whereHas('student', fn ($query) => $query->where('nim', $nim))
            ->first();
        $subject = $code === '' ? null : Subject::query()->where('code', $code)->first();

        if ($nim === '') {
            $errors[] = __('NIM is required.');
        } elseif ($placement === null) {
            $errors[] = __('NIM :nim is not placed in a class this semester. Import the class placements first.', ['nim' => $nim]);
        }

        if ($subject === null) {
            $errors[] = $code === '' ? __('Subject code is required.') : __('No subject with code :code.', ['code' => $code]);
        }

        $score = $rawScore === '' ? null : $this->grades->parseScore($rawScore);

        if ($rawScore !== '' && $score === null) {
            $errors[] = __('":score" is not a score from 0 to 100.', ['score' => $rawScore]);
        }

        if ($letter !== '' && ! $this->scale()->has($letter)) {
            $errors[] = __('":letter" is not a letter of the grade scale.', ['letter' => $letter]);
        }

        if ($rawScore === '' && $letter === '') {
            $errors[] = __('Give the score or the letter.');
        }

        $offering = null;
        $teacher = null;
        $plan = null;

        if ($placement !== null && $subject !== null) {
            $offering = CourseOffering::query()
                ->where('semester_id', $semester->id)
                ->where('subject_id', $subject->id)
                ->where('class_group_id', $placement->class_group_id)
                ->first();

            $plan = $subject->id.'|'.$placement->class_group_id;

            if ($offering === null && $nidm === '' && isset($this->planned[$plan])) {
                $teacher = Teacher::query()->find($this->planned[$plan]);
            } elseif ($offering === null) {
                $teacher = $nidm === '' ? null : Teacher::query()->where('nidm', $nidm)->first();

                if ($teacher === null) {
                    $errors[] = $nidm === ''
                        ? __('No class offering yet for :subject in :class: give the teacher_nidm.', ['subject' => $subject->code, 'class' => $placement->class_name])
                        : __('No muhadhir with NIDM :nidm.', ['nidm' => $nidm]);
                }
            } else {
                $existing = $offering->enrollments()->where('student_id', $placement->student_id)->whereNotNull('finalized_at')->first();

                if ($existing !== null) {
                    $errors[] = __('Already has a final grade (:letter). Correct it on the academic record page.', ['letter' => $existing->final_letter]);
                }
            }
        }

        // A teacher is only looked up for a missing offering, so $plan is set here.
        if ($errors === [] && $offering === null && $teacher !== null) {
            $this->planned[$plan] ??= $teacher->id;
        }

        $grade = $letter !== '' && $this->scale()->has($letter)
            ? ['letter' => $letter, 'point' => $this->scale()->get($letter)->point]
            : ($score !== null ? GradeScale::resolve($score, GradeScale::ordered()) : null);

        return ['input' => [
            'student_id' => $placement?->student_id,
            'class_group_id' => $placement?->class_group_id,
            'subject_id' => $subject?->id,
            'offering_id' => $offering?->id,
            'teacher_id' => $teacher?->id,
            'score' => $score,
            'letter' => $grade['letter'] ?? null,
            'point' => $grade['point'] ?? null,
        ], 'errors' => $errors];
    }

    public function import(?Semester $semester, array $input, User $by): void
    {
        if ($semester === null) {
            throw new LogicException('A semester is required.');
        }

        DB::transaction(function () use ($semester, $input, $by) {
            $offering = $input['offering_id'] !== null
                ? CourseOffering::query()->whereKey($input['offering_id'])->firstOrFail()
                : $this->createOffering($semester, $input);

            $now = now();

            $offering->enrollments()->updateOrCreate(['student_id' => $input['student_id']], [
                'final_score' => $input['score'],
                'final_letter' => $input['letter'],
                'final_point' => $input['point'],
                'finalized_at' => $now,
                'grade_note' => __('Imported from the archive of :semester.', ['semester' => $semester->label()]),
            ]);

            if (! $offering->isFinalized()) {
                $offering->update(['grades_finalized_at' => $now, 'grades_finalized_by' => $by->id]);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function createOffering(Semester $semester, array $input): CourseOffering
    {
        $subject = Subject::query()->whereKey($input['subject_id'])->firstOrFail();

        // Another row of the same file may have created it in the meantime.
        return CourseOffering::query()->firstOrCreate([
            'semester_id' => $semester->id,
            'subject_id' => $subject->id,
            'class_group_id' => $input['class_group_id'],
        ], [
            'teacher_id' => $input['teacher_id'],
            'subject_code' => $subject->code,
            'subject_name' => $subject->name,
            'credits' => $subject->credits,
        ]);
    }

    /**
     * @return Collection<string, GradeScale>
     */
    private function scale(): Collection
    {
        return $this->scale ??= GradeScale::ordered()->keyBy(fn (GradeScale $band) => Str::upper($band->letter));
    }
}
