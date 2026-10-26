<?php

namespace App\Actions\People;

use App\Enums\Role;
use App\Models\Guardian;
use App\Models\User;
use App\Support\CreatedAccount;
use App\Support\Credentials;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SaveGuardian
{
    /**
     * Create a wali santri login (with a generated code) or update one, and
     * link it to the given students.
     *
     * @param  array<string, mixed>  $input
     * @return CreatedAccount|null The new account, or null on update.
     */
    public function handle(?Guardian $guardian, array $input): ?CreatedAccount
    {
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($guardian?->user_id)],
            'relationship' => ['nullable', 'string', 'max:50'],
            'phone' => ['nullable', 'string', 'max:30'],
            'student_ids' => ['required', 'array', 'min:1'],
            'student_ids.*' => ['integer', 'distinct', Rule::exists('students', 'id')],
        ], [
            'student_ids.required' => __('Link at least one mahasantri.'),
        ])->validate();

        return DB::transaction(function () use ($guardian, $data) {
            $profile = ['relationship' => $data['relationship'] ?? null, 'phone' => $data['phone'] ?? null];

            if ($guardian !== null) {
                $guardian->user->update(['name' => $data['name'], 'email' => $data['email'] ?? null]);
                $guardian->update($profile);
                $guardian->students()->sync($data['student_ids']);

                return null;
            }

            $code = Credentials::guardianCode();
            $password = Credentials::temporaryPassword();

            $user = User::create([
                'username' => $code,
                'name' => $data['name'],
                'email' => $data['email'] ?? null,
                'password' => $password,
                'must_change_password' => true,
            ]);
            $user->assignRole(Role::Guardian);

            $guardian = $user->guardian()->create([...$profile, 'code' => $code]);
            $guardian->students()->sync($data['student_ids']);

            return new CreatedAccount($guardian, $code, $user->name, $password);
        });
    }
}
