<?php

namespace App\Support;

use App\Enums\DiscussionKind;
use App\Enums\Role;
use App\Enums\StudentStatus;
use App\Enums\TeacherStatus;
use App\Models\Discussion;
use App\Models\DiscussionMember;
use App\Models\LpppkSanction;
use App\Models\User;

/**
 * Who may read, post in and moderate a discussion room (v5 D26, D28).
 *
 * - Bimbingan groups: their lead muhadhir and members.
 * - LPPPK channels: every active mahasantri and muhadhir.
 * - Admin, Superadmin and the academic monitor see every room and moderate it;
 *   the lead muhadhir moderates their group.
 */
final class DiscussionAccess
{
    public static function oversees(User $user): bool
    {
        return $user->hasRole(Role::Admin, Role::Superadmin, Role::AcademicMonitor);
    }

    public static function canView(User $user, Discussion $discussion): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if (self::oversees($user)) {
            return true;
        }

        return match ($discussion->kind) {
            DiscussionKind::Bimbingan => self::member($user, $discussion) !== null,
            DiscussionKind::Lpppk, DiscussionKind::TanyaHukum => self::inLpppk($user) && ! (self::sanction($user)?->isRestricted() ?? false),
        };
    }

    public static function canModerate(User $user, Discussion $discussion): bool
    {
        if (self::oversees($user)) {
            return true;
        }

        return $discussion->lead_teacher_id !== null
            && $discussion->loadMissing('leadTeacher')->leadTeacher?->user_id === $user->id;
    }

    /**
     * Why $user cannot post now, or null when they can.
     */
    public static function whyNotPost(User $user, Discussion $discussion): ?string
    {
        if (! self::canView($user, $discussion)) {
            return __('You are not in this discussion.');
        }

        if ($discussion->closed_at !== null) {
            return __('This discussion is closed; it can only be read.');
        }

        if (self::canModerate($user, $discussion)) {
            return null;
        }

        if ($discussion->locked_at !== null) {
            return __('This discussion is locked by a moderator.');
        }

        $member = self::member($user, $discussion);
        $sanction = $discussion->kind !== DiscussionKind::Bimbingan ? self::sanction($user) : null;

        if ($sanction?->isMuted()) {
            return $sanction->muted_until
                ? __('You are muted in LPPPK until :date.', ['date' => $sanction->muted_until->translatedFormat('j M Y H:i')])
                : __('You are muted in LPPPK.');
        }

        if ($member?->isMuted()) {
            return $member->muted_until
                ? __('You are muted here until :date.', ['date' => $member->muted_until->translatedFormat('j M Y H:i')])
                : __('You are muted here.');
        }

        return null;
    }

    /**
     * The person's LPPPK-wide mute or restriction (v5 D28), if any.
     */
    public static function sanction(User $user): ?LpppkSanction
    {
        return LpppkSanction::query()->where('user_id', $user->id)->first();
    }

    /**
     * May $user open LPPPK at all (the channel list)?
     */
    public static function canUseLpppk(User $user): bool
    {
        return $user->is_active && (self::oversees($user) || (self::inLpppk($user) && ! (self::sanction($user)?->isRestricted() ?? false)));
    }

    public static function member(User $user, Discussion $discussion): ?DiscussionMember
    {
        return DiscussionMember::query()->where('discussion_id', $discussion->id)->where('user_id', $user->id)->first();
    }

    /**
     * Every active mahasantri and muhadhir belongs to LPPPK.
     */
    private static function inLpppk(User $user): bool
    {
        $user->loadMissing(['student', 'teacher']);

        return ($user->student !== null && $user->student->status === StudentStatus::Active)
            || ($user->teacher !== null && $user->teacher->status === TeacherStatus::Active);
    }
}
