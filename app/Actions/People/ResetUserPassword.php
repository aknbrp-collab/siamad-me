<?php

namespace App\Actions\People;

use App\Models\User;
use App\Support\Credentials;

class ResetUserPassword
{
    /**
     * Give the user a temporary password they must change at next login.
     * Returns the password so the admin can hand it over once.
     */
    public function handle(User $user): string
    {
        $password = Credentials::temporaryPassword();

        $user->forceFill([
            'password' => $password,
            'must_change_password' => true,
            'remember_token' => null,
        ])->save();

        return $password;
    }
}
