<?php

namespace App\Actions\Teaching;

use App\Models\CourseEnrollment;
use App\Models\GradeScale;
use App\Support\MaintenanceMode;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Bring grades imported from the archive in line with the current grade scale (after-review D54): only
 * grades imported with a score and no letter from the archive. Letters the archive gave, and grades entered
 * in classes, never change. They are in closed semesters, so maintenance mode must be on, as for the import.
 */
class ApplyGradeScale
{
    /**
     * The grades that would change: [enrollment, new letter, new point].
     *
     * @return list<array{enrollment: CourseEnrollment, letter: string, point: string}>
     */
    public function changes(): array
    {
        $scale = GradeScale::ordered();

        if ($scale->isEmpty()) {
            return [];
        }

        $changes = [];

        foreach ($this->candidates() as $enrollment) {
            $grade = GradeScale::resolve((float) $enrollment->final_score, $scale);

            if ($grade['letter'] !== $enrollment->final_letter || (float) $grade['point'] !== (float) $enrollment->final_point) {
                $changes[] = ['enrollment' => $enrollment, 'letter' => $grade['letter'], 'point' => $grade['point']];
            }
        }

        return $changes;
    }

    /**
     * Apply the scale; returns how many grades changed. Each change is in the audit log.
     */
    public function handle(): int
    {
        if (! MaintenanceMode::allowsEditing()) {
            throw ValidationException::withMessages(['scale' => __('Closed semesters are read-only. Ask the Superadmin to turn maintenance mode on first.')]);
        }

        if (GradeScale::query()->doesntExist()) {
            throw ValidationException::withMessages(['scale' => __('Set the grade scale first (Grade scale page): letters and points come from it.')]);
        }

        $changes = $this->changes();

        DB::transaction(function () use ($changes) {
            foreach ($changes as $change) {
                $change['enrollment']->update(['final_letter' => $change['letter'], 'final_point' => $change['point']]);
            }
        });

        return count($changes);
    }

    /**
     * @return Collection<int, CourseEnrollment>
     */
    private function candidates(): Collection
    {
        return CourseEnrollment::query()
            ->where('from_archive', true)
            ->where('archive_letter', false)
            ->whereNotNull('final_score')
            ->whereNotNull('finalized_at')
            ->get();
    }
}
