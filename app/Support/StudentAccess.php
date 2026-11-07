<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\Student;
use App\Models\User;

/**
 * Whose academic and finance records the person may read through the API
 * (v6 D37): a mahasantri their own, a wali santri their own children, and
 * academic staff anyone — the same people as on the web pages.
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
            Role::Admin, Role::Superadmin, Role::AcademicMonitor => true,
            default => false,
        };
    }
}
