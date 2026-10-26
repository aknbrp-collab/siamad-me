<?php

namespace App\Support;

use App\Enums\Gender;
use App\Enums\PaymentSource;
use App\Enums\Role;
use App\Models\Bill;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which bills the person acting in the finance pages may see and record
 * payments on. Admin Keuangan and Superadmin: all. A Petugas Pembayaran: only
 * bills of mahasantri of the gender in their scope; they never administer
 * bills (generate, void, change the instalment limit).
 */
final class BillAccess
{
    /**
     * The gender a Petugas Pembayaran is limited to (null if none is set: they then see nothing).
     */
    public static function gender(?User $user, ?Role $role): ?Gender
    {
        return $role === Role::PaymentCollector ? $user?->collectorScope() : null;
    }

    /**
     * @param  Builder<Bill>  $bills
     * @return Builder<Bill>
     */
    public static function scope(Builder $bills, ?User $user, ?Role $role): Builder
    {
        if ($role !== Role::PaymentCollector) {
            return $bills;
        }

        $gender = self::gender($user, $role);

        return $gender === null
            ? $bills->whereRaw('1 = 0')
            : $bills->whereHas('student', fn (Builder $query) => $query->where('gender', $gender));
    }

    public static function allows(?User $user, ?Role $role, Bill $bill): bool
    {
        if (in_array($role, [Role::AdminPayment, Role::Superadmin], true)) {
            return true;
        }

        $gender = self::gender($user, $role);

        return $role === Role::PaymentCollector
            && $gender !== null
            && $bill->loadMissing('student')->student->gender === $gender;
    }

    /**
     * Generate bills, void bills, change instalment limits: the finance office only.
     */
    public static function administers(?Role $role): bool
    {
        return in_array($role, [Role::AdminPayment, Role::Superadmin], true);
    }

    /**
     * The source recorded on payments entered by someone acting as $role.
     */
    public static function source(?Role $role): PaymentSource
    {
        return $role === Role::PaymentCollector ? PaymentSource::Collector : PaymentSource::Staff;
    }
}
