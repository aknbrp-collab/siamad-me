<?php

namespace App\Actions\Supervision;

use App\Enums\StudentStatus;
use App\Enums\TeacherStatus;
use App\Models\Student;
use App\Models\Supervision;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pembimbing akademik: the Admin assigns a muhadhir to supervise mahasantri.
 * One supervisor per mahasantri at a time; assigning another closes the
 * current supervision (ended today) instead of overwriting it.
 */
class ManageSupervision
{
    /**
     * @param  list<int>  $studentIds
     * @return int Number of mahasantri who got this supervisor (already supervised by them: not counted).
     */
    public function assign(Teacher $teacher, array $studentIds, User $by): int
    {
        if ($teacher->status !== TeacherStatus::Active) {
            throw ValidationException::withMessages(['supervisor' => __('Only an active muhadhir can be a pembimbing akademik.')]);
        }

        if ($studentIds === []) {
            throw ValidationException::withMessages(['students' => __('Choose at least one mahasantri.')]);
        }

        return DB::transaction(function () use ($teacher, $studentIds, $by) {
            $students = Student::query()
                ->with('currentSupervision')
                ->whereKey($studentIds)
                ->where('status', StudentStatus::Active)
                ->get();

            $assigned = 0;

            foreach ($students as $student) {
                $current = $student->currentSupervision;

                if ($current?->teacher_id === $teacher->id) {
                    continue;
                }

                $current?->update(['ended_on' => today()]);

                Supervision::create([
                    'student_id' => $student->id,
                    'teacher_id' => $teacher->id,
                    'started_on' => today(),
                    'assigned_by' => $by->id,
                ]);

                $assigned++;
            }

            return $assigned;
        });
    }

    /**
     * The mahasantri no longer has this supervisor (e.g. graduated or reassigned later).
     */
    public function end(Supervision $supervision): void
    {
        if ($supervision->ended_on !== null) {
            return;
        }

        $supervision->update(['ended_on' => today()]);
    }

    /**
     * Whether $user is the current pembimbing akademik of $student.
     */
    public static function supervises(User $user, Student $student): bool
    {
        $teacherId = $user->teacher?->id;

        return $teacherId !== null && Supervision::query()->current()
            ->where('teacher_id', $teacherId)
            ->where('student_id', $student->id)
            ->exists();
    }
}
