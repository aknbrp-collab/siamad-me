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

        // A mahasantri's name is on official documents (KHS, transcript): only the academic office changes it.
        if ($user->student()->exists()) {
            $input['name'] = $user->name;
        }

        $validated = Validator::make($input, $this->profileRules($user->id))->validate();

        $user->fill($validated)->save();
    }
}
