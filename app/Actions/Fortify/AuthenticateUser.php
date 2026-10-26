<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;

class AuthenticateUser
{
    /**
     * Resolve the user for a login attempt by ID number (NIM, NIDM,
     * guardian code or staff username) and password.
     */
    public function __invoke(Request $request): ?User
    {
        $user = User::query()
            ->where('username', $request->input(Fortify::username()))
            ->first();

        if (! $user || ! Hash::check((string) $request->input('password'), $user->password)) {
            return null;
        }

        // Only reveal the account state once the password has been proven.
        if (! $user->is_active) {
            throw ValidationException::withMessages([
                Fortify::username() => __('This account has been deactivated. Please contact the academic office.'),
            ]);
        }

        return $user;
    }
}
