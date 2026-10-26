<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * The role the signed-in user is currently acting as, kept in the session.
 * Users with several roles choose one; a single role is selected automatically.
 */
final class ActiveRole
{
    private const SESSION_KEY = 'active_role';

    public static function get(): ?Role
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return null;
        }

        $role = Role::tryFrom((string) session(self::SESSION_KEY));

        return $role !== null && $user->hasRole($role) ? $role : null;
    }

    public static function set(Role $role): void
    {
        session()->put(self::SESSION_KEY, $role->value);
    }

    public static function forget(): void
    {
        session()->forget(self::SESSION_KEY);
    }
}
