<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

#[Signature('siakad:superadmin {username? : Login ID for the account}')]
#[Description('Create a Superadmin account (use this for the first login on a new installation)')]
class CreateSuperadmin extends Command
{
    public function handle(): int
    {
        $username = Str::lower(trim((string) ($this->argument('username') ?? text('Login ID', required: true))));
        $name = text('Full name', required: true);
        $password = password('Password', required: true);

        $validator = Validator::make(
            ['username' => $username, 'name' => $name, 'password' => $password],
            [
                'username' => ['required', 'string', 'max:64', 'unique:users,username'],
                'name' => ['required', 'string', 'max:255'],
                'password' => ['required', 'string', Password::default()],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        DB::transaction(function () use ($username, $name, $password) {
            $user = User::create(['username' => $username, 'name' => $name, 'password' => $password]);
            $user->assignRole(Role::Superadmin);
        });

        $this->components->info("Superadmin [{$username}] created.");

        return self::SUCCESS;
    }
}
