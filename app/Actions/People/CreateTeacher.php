<?php

namespace App\Actions\People;

use App\Enums\DocumentType;
use App\Enums\Role;
use App\Enums\TeacherStatus;
use App\Models\User;
use App\Support\CreatedAccount;
use App\Support\Credentials;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CreateTeacher
{
    use StoresProfilePhoto, TeacherRules;

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function validate(array $input): array
    {
        return Validator::make($input, $this->teacherRules())->validate();
    }

    /**
     * Create the login, the ustadz profile and the missing-document rows.
     *
     * @param  array<string, mixed>  $input
     */
    public function handle(array $input): CreatedAccount
    {
        $data = $this->validate($input);

        return DB::transaction(function () use ($data) {
            $password = Credentials::temporaryPassword();

            $user = User::create([
                'username' => $data['nidm'],
                'name' => $data['name'],
                'email' => $data['email'] ?? null,
                'password' => $password,
                'must_change_password' => true,
            ]);
            $user->assignRole(Role::Teacher);

            $teacher = $user->teacher()->create([
                ...Arr::except($data, ['name', 'email', 'photo']),
                'status' => $data['status'] ?? TeacherStatus::Active,
                'photo_path' => $this->storePhoto($data['photo'] ?? null, 'teachers'),
            ]);

            foreach (DocumentType::cases() as $type) {
                $teacher->documents()->create(['type' => $type]);
            }

            return new CreatedAccount($teacher, $user->username, $user->name, $password);
        });
    }
}
