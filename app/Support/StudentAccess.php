<?php

namespace App\Support;

use App\Actions\Supervision\ManageSupervision;
use App\Enums\Role;
use App\Models\Student;
use App\Models\User;

/**
 * Whose academic and finance records the person may read through the API
 * (v6 D37): a mahasantri their own, a wali santri their own children, a
 * muhadhir their supervisees (pembimbing akademik), and academic staff
 * anyone — the same people as on the web pages.
 */
final class StudentAccess
{
    public static function canView(?User $user, ?Role $role, Student $student): bool
    {
        if ($user === null) {
            return false;
        }

        return match ($role) {
            Role::Student, Role::PaymentCollector => $student->user_id === $user->id,
            Role::Guardian => $user->guardian?->students()->whereKey($student->id)->exists() ?? false,
            Role::Teacher => ManageSupervision::supervises($user, $student),
            Role::Admin, Role::Superadmin, Role::AcademicMonitor => true,
            default => false,
        };
    }

    /**
     * Bills: the same people, except the pembimbing akademik (who sees grades, not finances).
     */
    public static function canViewBills(?User $user, ?Role $role, Student $student): bool
    {
        return $role !== Role::Teacher && self::canView($user, $role, $student);
    }
}
