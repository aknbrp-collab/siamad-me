<?php

namespace App\Actions\Academic;

use App\Enums\SemesterStatus;
use App\Models\CourseEnrollment;
use App\Models\Semester;
use Illuminate\Validation\ValidationException;

/**
 * planned ──activate──▶ active ──close──▶ closed ──reopen (Superadmin)──▶ active
 */
class SemesterLifecycle
{
    public function activate(Semester $semester): void
    {
        if ($semester->status !== SemesterStatus::Planned) {
            $this->fail(__('Only a planned semester can be activated.'));
        }

        $this->ensureNoOtherActive($semester);

        $semester->update(['status' => SemesterStatus::Active]);
    }

    /**
     * Number of course enrolments in the semester whose grades are not yet
     * finalised; closing with any left requires explicit confirmation.
     */
    public function unfinalizedCount(Semester $semester): int
    {
        return CourseEnrollment::query()
            ->whereNull('finalized_at')
            ->whereHas('courseOffering', fn ($query) => $query->where('semester_id', $semester->id))
            ->count();
    }

    public function close(Semester $semester, bool $confirmed = false): void
    {
        if ($semester->status !== SemesterStatus::Active) {
            $this->fail(__('Only the active semester can be closed.'));
        }

        $pending = $this->unfinalizedCount($semester);

        if ($pending > 0 && ! $confirmed) {
            $this->fail(__(':count course enrolments have no final grade yet. Confirm to close anyway.', ['count' => $pending]));
        }

        $semester->update(['status' => SemesterStatus::Closed]);
    }

    /**
     * Superadmin only (checked by the caller); the reason is kept and audited.
     */
    public function reopen(Semester $semester, string $reason): void
    {
        if ($semester->status !== SemesterStatus::Closed) {
            $this->fail(__('Only a closed semester can be reopened.'));
        }

        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reopenReason' => __('A reason is required to reopen a semester.')]);
        }

        $this->ensureNoOtherActive($semester);

        $semester->update(['status' => SemesterStatus::Active, 'status_note' => trim($reason)]);
    }

    private function ensureNoOtherActive(Semester $semester): void
    {
        $active = Semester::query()
            ->where('status', SemesterStatus::Active)
            ->whereKeyNot($semester->id)
            ->first();

        if ($active !== null) {
            $this->fail(__('Close :semester first; only one semester can be active.', ['semester' => $active->label()]));
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['semester' => $message]);
    }
}
