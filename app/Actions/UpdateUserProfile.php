<?php

namespace App\Actions;

use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\Validator;

class UpdateUserProfile
{
    use ProfileValidationRules;

    /**
     * Validate and save the name and optional email a user may edit themselves.
     *
     * @param  array<string, mixed>  $input
     */
    public function handle(User $user, array $input): void
    {
        $input['email'] = filled($input['email'] ?? null) ? $input['email'] : null;

        $validated = Validator::make($input, $this->profileRules($user->id))->validate();

        $user->fill($validated)->save();
    }
}
