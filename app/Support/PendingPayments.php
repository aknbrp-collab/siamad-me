<?php

namespace App\Support;

use App\Enums\HandoverStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentSource;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Models\Payment;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Where each unverified payment is waiting (v4 Step 9). A payment moves:
 * cash: recorded → held by its recorder → handed over → verified by a receiver;
 * transfer: recorded or confirmed → verified by a reviewer.
 * The people who may verify follow ReviewPayments: finance-office payments
 * need an Admin; payer and collector payments may also go to Admin Keuangan.
 *
 * @phpstan-type PendingRow array{payment: Payment, stage: string, from: User, since: CarbonInterface, days: int, holder: string, holder_id: int|null, roles: list<Role>}
 */
final class PendingPayments
{
    public const CASH_HELD = 'cash_held';

    public const HANDOVER_WAITING = 'handover_waiting';

    public const TRANSFER_WAITING = 'transfer_waiting';

    /**
     * @return array<string, string>
     */
    public static function stages(): array
    {
        return [
            self::CASH_HELD => __('Cash not handed over'),
            self::HANDOVER_WAITING => __('Handover not verified'),
            self::TRANSFER_WAITING => __('Transfer not checked'),
        ];
    }

    /**
     * One row per unverified payment, the longest waiting first.
     *
     * @return Collection<int, PendingRow>
     */
    public static function rows(): Collection
    {
        return Payment::query()
            ->with(['bill.student.user', 'recorder', 'handover.handedOverBy'])
            ->where('status', PaymentStatus::Unverified)
            ->get()
            ->map(fn (Payment $payment) => self::row($payment))
            ->sortBy(fn (array $row) => $row['since']->getTimestamp())
            ->values();
    }

    /**
     * Rows grouped by stage and holder: who has how many payments, how much,
     * and how long the oldest has waited.
     *
     * @param  Collection<int, PendingRow>  $rows
     * @return Collection<int, array{key: string, stage: string, holder: string, count: int, amount: int, days: int}>
     */
    public static function summary(Collection $rows): Collection
    {
        return $rows->groupBy(fn (array $row) => self::holderKey($row))
            ->map(fn (Collection $group, string $key) => [
                'key' => $key,
                'stage' => $group->first()['stage'],
                'holder' => $group->first()['holder'],
                'count' => $group->count(),
                'amount' => (int) $group->sum(fn (array $row) => $row['payment']->amount),
                'days' => (int) $group->max('days'),
            ])
            ->sortByDesc('days')
            ->values();
    }

    /**
     * @param  PendingRow  $row
     */
    public static function holderKey(array $row): string
    {
        return $row['stage'].':'.($row['holder_id'] ?? implode(',', array_map(fn (Role $role) => $role->value, $row['roles'])));
    }

    /**
     * Remind the people a group of payments is waiting with, at most once a
     * day per person and group. Returns how many people were reminded.
     */
    public static function remind(string $key, User $by): int
    {
        $group = self::rows()->filter(fn (array $row) => self::holderKey($row) === $key);

        if ($group->isEmpty()) {
            return 0;
        }

        $first = $group->first();
        $recipients = $first['holder_id'] !== null
            ? User::query()->whereKey($first['holder_id'])->get()
            : User::query()->whereHas('roles', fn ($query) => $query->whereIn('role', $first['roles']))->get();

        $amount = Money::format((int) $group->sum(fn (array $row) => $row['payment']->amount));
        $title = match ($first['stage']) {
            self::CASH_HELD => __('Please hand over :count cash payments (:amount)', ['count' => $group->count(), 'amount' => $amount]),
            self::HANDOVER_WAITING => __(':count handed-over payments wait for verification (:amount)', ['count' => $group->count(), 'amount' => $amount]),
            default => __(':count transfers wait to be checked (:amount)', ['count' => $group->count(), 'amount' => $amount]),
        };
        $body = __('The oldest has waited :days days. Reminder from :name.', ['days' => $group->max('days'), 'name' => $by->name]);

        return $recipients
            ->reject(fn (User $user) => $user->is($by))
            ->filter(fn (User $user) => Notify::remindOnce($user, 'pending-payments:'.$key.':'.today()->toDateString(), 'payment', $title, $body, self::urlFor($first['stage'], $user), 'clock'))
            ->count();
    }

    /**
     * The page where this person deals with the stage: the recorder hands
     * over on Cash handovers; Admin verifies on Payment verification, Admin
     * Keuangan on Transfer confirmations (transfers and collector handovers).
     */
    private static function urlFor(string $stage, User $user): string
    {
        if ($stage === self::CASH_HELD) {
            return route('finance.handovers');
        }

        if ($user->hasRole(Role::Admin)) {
            return route('admin.payments', ['tab' => $stage === self::TRANSFER_WAITING ? 'transfers' : 'handovers']);
        }

        return route('finance.transfers');
    }

    /**
     * @return PendingRow
     */
    private static function row(Payment $payment): array
    {
        $roles = $payment->source === PaymentSource::Staff
            ? [Role::Admin, Role::Superadmin]
            : [Role::Admin, Role::Superadmin, Role::AdminPayment];
        $reviewers = $payment->source === PaymentSource::Staff ? __('Admin') : __('Admin or Admin Keuangan');

        if ($payment->method === PaymentMethod::Cash && ($payment->handover === null || $payment->handover->status !== HandoverStatus::Submitted)) {
            $stage = self::CASH_HELD;
            $from = $payment->recorder;
            $since = $payment->created_at;
            $holder = $payment->recorder->name;
            $holderId = $payment->recorder->id;
        } elseif ($payment->method === PaymentMethod::Cash) {
            $stage = self::HANDOVER_WAITING;
            $from = $payment->handover->handedOverBy;
            $since = $payment->handover->submitted_at;
            $holder = $reviewers;
            $holderId = null;
        } else {
            $stage = self::TRANSFER_WAITING;
            $from = $payment->recorder;
            $since = $payment->created_at;
            $holder = $reviewers;
            $holderId = null;
        }

        return [
            'payment' => $payment,
            'stage' => $stage,
            'from' => $from,
            'since' => $since,
            'days' => (int) $since->startOfDay()->diffInDays(today()),
            'holder' => $holder,
            'holder_id' => $holderId,
            'roles' => $holderId === null ? $roles : [],
        ];
    }
}
