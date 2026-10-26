<?php

namespace App\Actions\Enrollment;

use App\Models\ClassEnrollment;
use App\Models\CourseEnrollment;
use App\Models\CourseOffering;

/**
 * Keeps an offering's course enrolments equal to its class roster for that
 * semester. Rows that already carry grade data are never removed.
 */
class SyncOfferingEnrollments
{
    public function handle(CourseOffering $offering): void
    {
        $roster = ClassEnrollment::query()
            ->where('semester_id', $offering->semester_id)
            ->where('class_group_id', $offering->class_group_id)
            ->pluck('student_id');

        $existing = $offering->enrollments()->get();

        foreach ($roster->diff($existing->pluck('student_id')) as $studentId) {
            $offering->enrollments()->create(['student_id' => $studentId]);
        }

        $existing
            ->reject(fn (CourseEnrollment $enrollment) => $roster->contains($enrollment->student_id))
            ->reject(fn (CourseEnrollment $enrollment) => $enrollment->hasGradeData())
            ->each(fn (CourseEnrollment $enrollment) => $enrollment->delete());
    }

    /**
     * Re-sync every offering of a class in a semester.
     */
    public function forClass(int $semesterId, int $classGroupId): void
    {
        CourseOffering::query()
            ->where('semester_id', $semesterId)
            ->where('class_group_id', $classGroupId)
            ->get()
            ->each(fn (CourseOffering $offering) => $this->handle($offering));
    }
}
