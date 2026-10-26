<?php

namespace App\Actions\Teaching;

use App\Models\CourseEnrollment;
use App\Models\CourseOffering;
use App\Models\GradeComponent;
use App\Models\GradeScale;
use App\Models\GradeScore;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Grade components (weights add up to 100), score entry, finalisation and
 * the Admin unlock. Finalised grades are locked; the letter and point are
 * snapshotted so later grade-scale changes never rewrite history.
 */
class ManageGrades
{
    /**
     * Replace the offering's components. Rows with an id update that
     * component; missing ones are removed (only if they have no scores).
     *
     * @param  list<array{id?: int|null, name: string, weight: int|string|null}>  $rows
     */
    public function saveComponents(CourseOffering $offering, array $rows): void
    {
        $this->ensureEditable($offering);

        $names = [];
        $total = 0;

        foreach ($rows as $index => $row) {
            $name = trim((string) $row['name']);
            $weight = filter_var($row['weight'], FILTER_VALIDATE_INT);

            if ($name === '' || mb_strlen($name) > 50) {
                $this->fail("components.{$index}.name", __('Give each component a name (max 50 characters).'));
            }

            if (in_array(mb_strtolower($name), $names, true)) {
                $this->fail("components.{$index}.name", __('Component names must be different.'));
            }

            if ($weight === false || $weight < 1 || $weight > 100) {
                $this->fail("components.{$index}.weight", __('Weight must be a whole number from 1 to 100.'));
            }

            $names[] = mb_strtolower($name);
            $total += $weight;
        }

        if ($rows === [] || $total !== 100) {
            $this->fail('components', __('The weights must add up to 100 (now :total).', ['total' => $total]));
        }

        DB::transaction(function () use ($offering, $rows) {
            $existing = $offering->components()->get()->keyBy('id');
            $keep = [];

            foreach ($rows as $position => $row) {
                $attributes = ['name' => trim((string) $row['name']), 'weight' => (int) $row['weight'], 'position' => $position];
                $component = isset($row['id']) ? $existing->get((int) $row['id']) : null;

                if ($component !== null) {
                    $component->update($attributes);
                } else {
                    $component = $offering->components()->create($attributes);
                }

                $keep[] = $component->id;
            }

            foreach ($existing->except($keep) as $removed) {
                if ($removed->scores()->exists()) {
                    $this->fail('components', __('":name" already has scores; clear them before removing the component.', ['name' => $removed->name]));
                }

                $removed->delete();
            }
        });
    }

    /**
     * Save scores. A blank score removes it.
     *
     * @param  array<int|string, array<int|string, mixed>>  $scores  course_enrollment_id => [component_id => score]
     */
    public function saveScores(CourseOffering $offering, array $scores): void
    {
        $this->ensureEditable($offering);

        $enrollmentIds = $offering->enrollments()->pluck('id')->all();
        $componentIds = $offering->components()->pluck('id')->all();

        foreach ($scores as $enrollmentId => $row) {
            foreach ($row as $componentId => $value) {
                if (! in_array((int) $enrollmentId, $enrollmentIds, true) || ! in_array((int) $componentId, $componentIds, true)) {
                    $this->fail('scores', __('Unknown mahasantri or component.'));
                }

                if (! $this->isBlank($value) && $this->parseScore($value) === null) {
                    $this->fail("scores.{$enrollmentId}.{$componentId}", __('Scores are numbers from 0 to 100.'));
                }
            }
        }

        DB::transaction(function () use ($scores) {
            foreach ($scores as $enrollmentId => $row) {
                foreach ($row as $componentId => $value) {
                    $key = ['course_enrollment_id' => (int) $enrollmentId, 'grade_component_id' => (int) $componentId];

                    if ($this->isBlank($value)) {
                        GradeScore::query()->where($key)->first()?->delete();
                    } else {
                        GradeScore::query()->updateOrCreate($key, ['score' => $this->parseScore($value)]);
                    }
                }
            }
        });
    }

    /**
     * The weighted final score, or null while a component score is missing.
     *
     * @param  Collection<int, GradeComponent>  $components
     */
    public function finalScore(CourseEnrollment $enrollment, Collection $components): ?float
    {
        $scores = $enrollment->scores->keyBy('grade_component_id');
        $total = 0.0;

        foreach ($components as $component) {
            $score = $scores->get($component->id);

            if ($score === null) {
                return null;
            }

            $total += (float) $score->score * $component->weight / 100;
        }

        return round($total, 2);
    }

    /**
     * Lock the grades: every mahasantri needs every component score.
     */
    public function finalize(CourseOffering $offering, User $by): void
    {
        $this->ensureEditable($offering);

        $components = $offering->components()->get();

        if ((int) $components->sum('weight') !== 100) {
            $this->fail('grades', __('Set grade components whose weights add up to 100 first.'));
        }

        $enrollments = $offering->enrollments()->with(['scores', 'student.user'])->get();

        if ($enrollments->isEmpty()) {
            $this->fail('grades', __('There are no mahasantri in this offering.'));
        }

        $missing = $enrollments->filter(fn (CourseEnrollment $enrollment) => $this->finalScore($enrollment, $components) === null);

        if ($missing->isNotEmpty()) {
            $this->fail('grades', __('Scores are missing for: :names.', [
                'names' => $missing->map(fn (CourseEnrollment $enrollment) => $enrollment->student->user->name)->join(', '),
            ]));
        }

        DB::transaction(function () use ($offering, $by, $components, $enrollments) {
            $scale = GradeScale::ordered();
            $now = now();

            foreach ($enrollments as $enrollment) {
                $score = (float) $this->finalScore($enrollment, $components);
                $grade = GradeScale::resolve($score, $scale);

                $enrollment->update([
                    'final_score' => $score,
                    'final_letter' => $grade['letter'],
                    'final_point' => $grade['point'],
                    'finalized_at' => $now,
                ]);
            }

            $offering->update(['grades_finalized_at' => $now, 'grades_finalized_by' => $by->id]);
        });
    }

    /**
     * Admin / Superadmin only (checked by the caller): reopen finalised grades
     * for a correction. Only while the semester is open; the reason is kept.
     */
    public function unlock(CourseOffering $offering, string $reason): void
    {
        $offering->loadMissing('semester')->semester->assertOpen();

        if (! $offering->isFinalized()) {
            $this->fail('grades', __('These grades are not finalised.'));
        }

        if (trim($reason) === '') {
            $this->fail('unlockReason', __('Give a reason for unlocking the grades.'));
        }

        DB::transaction(function () use ($offering, $reason) {
            $offering->enrollments()->get()->each(fn (CourseEnrollment $enrollment) => $enrollment->update([
                'final_score' => null, 'final_letter' => null, 'final_point' => null, 'finalized_at' => null,
            ]));

            $offering->update(['grades_finalized_at' => null, 'grades_finalized_by' => null, 'grades_note' => trim($reason)]);
        });
    }

    public function ensureEditable(CourseOffering $offering): void
    {
        $offering->loadMissing('semester')->semester->assertOpen();

        if ($offering->isFinalized()) {
            $this->fail('grades', __('Grades are finalised and locked. Ask the Admin to unlock them for a correction.'));
        }
    }

    public function parseScore(mixed $value): ?float
    {
        $normalized = str_replace(',', '.', trim((string) $value));

        if (! is_numeric($normalized)) {
            return null;
        }

        $score = round((float) $normalized, 2);

        return $score >= 0 && $score <= 100 ? $score : null;
    }

    private function isBlank(mixed $value): bool
    {
        return $value === null || trim((string) $value) === '';
    }

    private function fail(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
