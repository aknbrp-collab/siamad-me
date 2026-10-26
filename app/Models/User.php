<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Enums\Gender;
use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $username
 * @property string $name
 * @property string|null $email
 * @property string $password
 * @property bool $is_active
 * @property bool $must_change_password
 * @property Carbon|null $last_login_at
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, UserRole> $roles
 */
#[Fillable(['username', 'name', 'email', 'password', 'is_active', 'must_change_password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use Auditable, HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * Usernames (NIM, NIDM, guardian code, staff ID) are stored trimmed and
     * lowercased, matching Fortify's lowercased login input.
     *
     * @return Attribute<string, string>
     */
    protected function username(): Attribute
    {
        return Attribute::make(
            set: fn (string $value) => Str::lower(trim($value)),
        );
    }

    /**
     * @return HasMany<UserRole, $this>
     */
    public function roles(): HasMany
    {
        return $this->hasMany(UserRole::class);
    }

    /**
     * @return HasOne<Student, $this>
     */
    public function student(): HasOne
    {
        return $this->hasOne(Student::class);
    }

    /**
     * @return HasOne<Teacher, $this>
     */
    public function teacher(): HasOne
    {
        return $this->hasOne(Teacher::class);
    }

    /**
     * @return HasOne<Guardian, $this>
     */
    public function guardian(): HasOne
    {
        return $this->hasOne(Guardian::class);
    }

    /**
     * The roles held by the user, in the enum's declaration order.
     *
     * @return list<Role>
     */
    public function roleList(): array
    {
        $held = $this->loadMissing('roles')->roles->pluck('role')->all();

        return array_values(array_filter(Role::cases(), fn (Role $role) => in_array($role, $held, true)));
    }

    public function hasRole(Role ...$roles): bool
    {
        return $this->loadMissing('roles')->roles->contains(fn (UserRole $assignment) => in_array($assignment->role, $roles, true));
    }

    public function hasStaffRole(): bool
    {
        return $this->hasRole(...Role::staff());
    }

    /**
     * Password, login status and email of an account holding a staff role
     * are managed by a Superadmin only, so an Admin can never take over a
     * Superadmin or Admin Payment login (e.g. of an ustadz with that role).
     */
    public function accountManageableBy(?Role $actingAs): bool
    {
        return $actingAs === Role::Superadmin || ! $this->hasStaffRole();
    }

    /**
     * The gender a Petugas Pembayaran handles, or null if the user is not one.
     */
    public function collectorScope(): ?Gender
    {
        return $this->loadMissing('roles')->roles->firstWhere('role', Role::PaymentCollector)?->scope;
    }

    public function assignRole(Role $role): void
    {
        if (! $this->hasRole($role)) {
            $this->roles()->create(['role' => $role]);
            $this->unsetRelation('roles');
        }
    }

    public function removeRole(Role $role): void
    {
        $this->loadMissing('roles')->roles->firstWhere('role', $role)?->delete();
        $this->unsetRelation('roles');
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }
}
