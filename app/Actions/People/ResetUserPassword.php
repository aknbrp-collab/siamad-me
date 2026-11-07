<?php

namespace App\Actions\People;

use App\Models\User;
use App\Support\Credentials;

class ResetUserPassword
{
    /**
     * Give the user a temporary password they must change at next login, and
     * sign out their app devices. Returns the password so the admin can hand
     * it over once.
     */
    public function handle(User $user): string
    {
        $password = Credentials::temporaryPassword();

        $user->forceFill([
            'password' => $password,
            'must_change_password' => true,
            'remember_token' => null,
        ])->save();

        // The mobile app must log in again with the new password (v6 D37).
        $user->tokens()->delete();

        return $password;
    }
}
