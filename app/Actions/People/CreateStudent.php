<?php

namespace App\Actions\People;

use App\Enums\DocumentType;
use App\Enums\Role;
use App\Enums\StudentStatus;
use App\Models\User;
use App\Support\CreatedAccount;
use App\Support\Credentials;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CreateStudent
{
    use StoresProfilePhoto, StudentRules;

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function validate(array $input): array
    {
        return Validator::make($input, $this->studentRules())->validate();
    }

    /**
     * Create the login, the mahasantri profile and the missing-document rows.
     *
     * @param  array<string, mixed>  $input
     */
    public function handle(array $input): CreatedAccount
    {
        $data = $this->validate($input);

        return DB::transaction(function () use ($data) {
            $password = Credentials::temporaryPassword();

            $user = User::create([
                'username' => $data['nim'],
                'name' => $data['name'],
                'email' => $data['email'] ?? null,
                'password' => $password,
                'must_change_password' => true,
            ]);
            $user->assignRole(Role::Student);

            $student = $user->student()->create([
                ...Arr::except($data, ['name', 'email', 'photo']),
                'status' => $data['status'] ?? StudentStatus::Active,
                'photo_path' => $this->storePhoto($data['photo'] ?? null, 'students'),
            ]);

            foreach (DocumentType::cases() as $type) {
                $student->documents()->create(['type' => $type]);
            }

            return new CreatedAccount($student, $user->username, $user->name, $password);
        });
    }
}
