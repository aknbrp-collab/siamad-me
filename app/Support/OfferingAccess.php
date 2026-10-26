<?php

namespace App\Support;

use App\Models\CourseEnrollment;
use App\Models\CourseOffering;
use App\Models\User;

/**
 * Who may use an offering's classroom: its ustadz manages it, enrolled
 * mahasantri take part.
 */
final class OfferingAccess
{
    public static function teaches(?User $user, CourseOffering $offering): bool
    {
        return $user !== null && $offering->teacher()->value('user_id') === $user->id;
    }

    public static function enrollment(?User $user, CourseOffering $offering): ?CourseEnrollment
    {
        $studentId = $user?->student()->value('id');

        return $studentId === null ? null : $offering->enrollments()->where('student_id', $studentId)->first();
    }

    public static function participates(?User $user, CourseOffering $offering): bool
    {
        return self::teaches($user, $offering) || self::enrollment($user, $offering) !== null;
    }
}
