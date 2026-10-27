<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\MaintenanceSession;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Maintenance mode (v3 D6): while Superadmin has it on, Admin, Admin
 * Keuangan and Superadmin may change records of closed semesters and past
 * dates, to enter previous-year data. Every change made meanwhile is tagged
 * in the audit log with the session. Off, everything is locked as usual.
 */
final class MaintenanceMode
{
    private const CACHE_KEY = 'maintenance.session';

    /**
     * Roles that may edit closed records while the mode is on.
     */
    public const EDITORS = [Role::Superadmin, Role::Admin, Role::AdminPayment];

    public static function current(): ?MaintenanceSession
    {
        $id = self::currentId();

        return $id === null ? null : MaintenanceSession::query()->with('starter')->find($id);
    }

    public static function currentId(): ?int
    {
        $id = Cache::rememberForever(self::CACHE_KEY, fn () => (int) MaintenanceSession::query()->whereNull('ended_at')->latest('id')->value('id'));

        return $id > 0 ? $id : null;
    }

    public static function isOn(): bool
    {
        return self::currentId() !== null;
    }

    /**
     * Whether the person acting now may change closed records.
     */
    public static function allowsEditing(): bool
    {
        return self::isOn() && in_array(ActiveRole::get(), self::EDITORS, true);
    }

    public static function turnOn(User $by, string $reason): MaintenanceSession
    {
        if (self::isOn()) {
            throw ValidationException::withMessages(['reason' => __('Maintenance mode is already on.')]);
        }

        $data = Validator::make(['reason' => trim($reason)], ['reason' => ['required', 'string', 'min:5', 'max:500']])->validate();

        $session = MaintenanceSession::create(['started_by' => $by->id, 'reason' => $data['reason'], 'started_at' => now()]);
        Cache::forget(self::CACHE_KEY);

        return $session;
    }

    public static function turnOff(User $by): void
    {
        $session = self::current();

        if ($session === null) {
            return;
        }

        $session->update(['ended_by' => $by->id, 'ended_at' => now()]);
        Cache::forget(self::CACHE_KEY);
    }
}
