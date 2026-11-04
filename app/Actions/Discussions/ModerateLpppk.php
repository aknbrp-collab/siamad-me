<?php

namespace App\Actions\Discussions;

use App\Models\LpppkSanction;
use App\Models\User;
use App\Support\DiscussionAccess;
use App\Support\Notify;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

/**
 * LPPPK-wide moderation (v5 D28) by Admin, Superadmin and the academic
 * monitor: mute a person in every channel (until a time or until lifted), or
 * restrict them (no access to LPPPK). The person is told, with the reason.
 */
class ModerateLpppk
{
    public function mute(User $person, ?CarbonInterface $until, ?string $reason, User $by): LpppkSanction
    {
        $this->ensureAllowed($person, $by);

        if ($until !== null && $until->isPast()) {
            throw ValidationException::withMessages(['mutedUntil' => __('Choose a time in the future, or no end.')]);
        }

        $sanction = $this->decide($person, ['muted_at' => now(), 'muted_until' => $until], $reason, $by);

        $this->tell($person, $until
            ? __('You are muted in LPPPK until :date', ['date' => $until->translatedFormat('j M Y H:i')])
            : __('You are muted in LPPPK'), $reason, $by);

        return $sanction;
    }

    public function restrict(User $person, ?string $reason, User $by): LpppkSanction
    {
        $this->ensureAllowed($person, $by);

        $sanction = $this->decide($person, ['restricted_at' => now()], $reason, $by);
        $this->tell($person, __('Your access to LPPPK was closed'), $reason, $by);

        return $sanction;
    }

    /**
     * Lift every LPPPK mute and restriction of the person.
     */
    public function lift(User $person, User $by): void
    {
        $this->ensureAllowed($person, $by);

        $sanction = LpppkSanction::query()->where('user_id', $person->id)->first();

        if ($sanction === null) {
            return;
        }

        $sanction->update(['muted_at' => null, 'muted_until' => null, 'restricted_at' => null, 'reason' => null, 'decided_by' => $by->id]);
        $this->tell($person, __('You can take part in LPPPK again'), null, $by);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function decide(User $person, array $changes, ?string $reason, User $by): LpppkSanction
    {
        $reason = trim((string) $reason);

        if (mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => __('Keep the reason under 500 characters.')]);
        }

        $sanction = LpppkSanction::query()->firstOrNew(['user_id' => $person->id]);
        $sanction->fill([...$changes, 'reason' => $reason !== '' ? $reason : null, 'decided_by' => $by->id])->save();

        return $sanction;
    }

    private function tell(User $person, string $title, ?string $reason, User $by): void
    {
        Notify::send([$person], 'discussion', $title, filled($reason) ? (string) $reason : __('Decided by the LPPPK moderators.'), route('lpppk'), 'shield-exclamation', $by);
    }

    private function ensureAllowed(User $person, User $by): void
    {
        if (! DiscussionAccess::oversees($by)) {
            throw ValidationException::withMessages(['person' => __('Only Admin and the academic monitor moderate LPPPK.')]);
        }

        if ($person->is($by) || DiscussionAccess::oversees($person)) {
            throw ValidationException::withMessages(['person' => __('This person cannot be muted here.')]);
        }
    }
}
