<?php

namespace App\Actions;

use App\Concerns\PasswordValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * A user changes their own password (also a temporary one). Their app
 * devices are signed out, except the one making the change ($keepTokenId).
 */
class ChangeOwnPassword
{
    use PasswordValidationRules;

    /**
     * @param  array<string, mixed>  $input  current_password, password, password_confirmation
     */
    public function handle(User $user, array $input, ?int $keepTokenId = null): void
    {
        $data = Validator::make($input, [
            'current_password' => ['required', 'string'],
            'password' => [...$this->passwordRules(), 'different:current_password'],
        ])->validate();

        if (! Hash::check((string) $data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => __('The password is incorrect.')]);
        }

        $user->forceFill(['password' => $data['password'], 'must_change_password' => false])->save();

        $user->tokens()->when($keepTokenId !== null, fn ($query) => $query->whereKeyNot($keepTokenId))->delete();
    }
}
