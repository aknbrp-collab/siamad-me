<?php

namespace App\Actions\Teaching;

use App\Models\CourseEnrollment;
use App\Models\GradeScale;
use App\Support\MaintenanceMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Set or correct one final grade of a closed semester, in maintenance mode
 * only (v3 Step 3), with a reason. The old value stays in the audit log.
 */
class CorrectGrade
{
    public function __construct(private readonly ManageGrades $grades) {}

    /**
     * @param  array{score?: mixed, letter?: mixed, reason?: mixed}  $input
     */
    public function handle(CourseEnrollment $enrollment, array $input): CourseEnrollment
    {
        $offering = $enrollment->loadMissing('courseOffering.semester')->courseOffering;

        if (! $offering->semester->isLocked() || ! MaintenanceMode::allowsEditing()) {
            throw ValidationException::withMessages(['reason' => __('Grades of a closed semester can only be corrected while maintenance mode is on. In an open semester, unlock the grades instead.')]);
        }

        $scale = GradeScale::ordered()->keyBy(fn (GradeScale $band) => Str::upper($band->letter));
        $letter = Str::upper(trim((string) ($input['letter'] ?? '')));

        $data = Validator::make(['score' => trim((string) ($input['score'] ?? '')), 'letter' => $letter, 'reason' => trim((string) ($input['reason'] ?? ''))], [
            'score' => ['nullable', 'required_without:letter', 'numeric', 'between:0,100'],
            'letter' => ['nullable', Rule::in($scale->keys()->all())],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ], [
            'score.required_without' => __('Give the score or the letter.'),
            'letter.in' => __('":letter" is not a letter of the grade scale.', ['letter' => $letter]),
        ])->validate();

        $score = $data['score'] === null || $data['score'] === '' ? null : $this->grades->parseScore($data['score']);
        $grade = $letter !== '' ? ['letter' => $letter, 'point' => $scale->get($letter)->point] : GradeScale::resolve((float) $score);

        return DB::transaction(function () use ($enrollment, $offering, $score, $grade, $data) {
            $enrollment->update([
                'final_score' => $score,
                'final_letter' => $grade['letter'],
                'final_point' => $grade['point'],
                'finalized_at' => $enrollment->finalized_at ?? now(),
                'grade_note' => $data['reason'],
            ]);

            if (! $offering->isFinalized()) {
                $offering->update(['grades_finalized_at' => now(), 'grades_finalized_by' => auth()->id()]);
            }

            return $enrollment;
        });
    }
}
