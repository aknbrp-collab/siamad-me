<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Enums\Role;
use App\Models\User;
use App\Support\ActiveRole;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * For signed download links (ApiFiles): act as the person and role the link
 * was made for, so the web controllers' access rules apply unchanged.
 */
trait ActsForSignedUser
{
    protected function actForSignedUser(Request $request): User
    {
        $user = User::query()->whereKey($request->integer('user'))->where('is_active', true)->firstOrFail();

        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);

        $role = Role::tryFrom((string) $request->query('role'));

        if ($role !== null && $user->hasRole($role)) {
            $request->attributes->set(ActiveRole::REQUEST_KEY, $role);
        }

        return $user;
    }
}
