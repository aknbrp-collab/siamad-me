<?php

namespace App\Actions\People;

use App\Enums\Role;
use App\Models\User;
use App\Rules\UniqueUsername;
use App\Support\CreatedAccount;
use App\Support\Credentials;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Staff accounts (Superadmin, Admin, Admin Payment, Academic Monitor) and the
 * staff roles of any user, e.g. an ustadz who is also the academic monitor.
 * Ustadz, mahasantri and wali santri roles belong to their profiles and are
 * never granted or removed here.
 */
class ManageUserAccounts
{
    /**
     * Create a staff login with a temporary password.
     *
     * @param  array<string, mixed>  $input
     */
    public function create(array $input): CreatedAccount
    {
        $data = Validator::make($input, [
            ...$this->accountRules(null),
            ...$this->roleRules(),
        ])->validate();

        return DB::transaction(function () use ($data) {
            $password = Credentials::temporaryPassword();

            $user = User::create([
                'username' => $data['username'],
                'name' => $data['name'],
                'email' => $data['email'] ?? null,
                'password' => $password,
                'must_change_password' => true,
            ]);

            foreach ($data['roles'] as $role) {
                $user->assignRole(Role::from($role));
            }

            return new CreatedAccount($user, $user->username, $user->name, $password);
        });
    }

    /**
     * Update the login ID, name and email of a staff-only account. People
     * with a profile are edited on their mahasantri / ustadz / wali page.
     *
     * @param  array<string, mixed>  $input
     */
    public function update(User $user, array $input): void
    {
        if ($this->hasProfile($user)) {
            $this->fail(__('Edit this person on their profile page.'), 'username');
        }

        $data = Validator::make($input, $this->accountRules($user))->validate();

        $user->update([
            'username' => $data['username'],
            'name' => $data['name'],
            'email' => $data['email'] ?? null,
        ]);
    }

    /**
     * Replace the user's staff roles; profile roles are kept as they are.
     *
     * @param  list<string>  $roles
     */
    public function syncStaffRoles(User $user, array $roles, User $actor): void
    {
        $data = Validator::make(['roles' => $roles], $this->roleRules(required: false))->validate();
        $wanted = array_map(fn (string $role) => Role::from($role), $data['roles'] ?? []);

        if ($wanted !== [] && $user->hasRole(Role::Student, Role::Guardian)) {
            $this->fail(__('Mahasantri and wali santri accounts can not hold staff roles.'));
        }

        if ($user->is($actor) && ! in_array(Role::Superadmin, $wanted, true)) {
            $this->fail(__('You can not remove your own Superadmin role.'));
        }

        $profileRoles = array_filter($user->roleList(), fn (Role $role) => ! $role->isStaff());

        if ($wanted === [] && $profileRoles === []) {
            $this->fail(__('An account needs at least one role.'));
        }

        DB::transaction(function () use ($user, $wanted) {
            foreach (Role::staff() as $role) {
                in_array($role, $wanted, true) ? $user->assignRole($role) : $user->removeRole($role);
            }
        });
    }

    public function hasProfile(User $user): bool
    {
        return $user->student()->exists() || $user->teacher()->exists() || $user->guardian()->exists();
    }

    /**
     * @return array<string, mixed>
     */
    private function accountRules(?User $user): array
    {
        return [
            'username' => ['required', 'string', 'min:3', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/', new UniqueUsername($user?->id)],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user?->id)],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function roleRules(bool $required = true): array
    {
        return [
            'roles' => [$required ? 'required' : 'present', 'array', ...($required ? ['min:1'] : [])],
            'roles.*' => ['string', 'distinct', Rule::in(array_map(fn (Role $role) => $role->value, Role::staff()))],
        ];
    }

    private function fail(string $message, string $key = 'roles'): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
