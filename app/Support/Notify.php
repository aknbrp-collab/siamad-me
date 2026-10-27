<?php

namespace App\Support;

use App\Jobs\SendWebPush;
use App\Models\SentReminder;
use App\Models\User;
use App\Notifications\SiakadNotice;
use Closure;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Sends in-app notifications. Never to the person who caused the event, nor
 * to deactivated accounts. With a group key, a recipient who still has an
 * unread notification for the same group gets that one updated ("3 new
 * messages") instead of a new one. Each one also pops up on the devices the
 * recipient turned Web Push on for (see App\Support\WebPush).
 */
final class Notify
{
    /**
     * @param  iterable<User>  $recipients
     * @param  string|Closure(User): string  $url  a fixed link, or one per recipient
     * @param  string|null  $countedBody  body when grouped, with :count (e.g. ":count new messages in …")
     */
    public static function send(
        iterable $recipients,
        string $kind,
        string $title,
        string $body,
        string|Closure $url,
        string $icon,
        ?User $actor = null,
        ?string $group = null,
        ?string $countedBody = null,
    ): void {
        $users = Collection::make($recipients)
            ->filter(fn (User $user) => $user->is_active && ! $user->is($actor))
            ->unique('id');

        foreach ($users as $user) {
            $link = self::path($url instanceof Closure ? $url($user) : $url);

            $bumped = $group !== null ? self::bumpUnread($user, $group, $title, $countedBody ?? $body, $link) : null;

            if ($bumped !== null) {
                self::push($user, $bumped->id, $title, (string) $bumped->data['body'], $group);

                continue;
            }

            self::push($user, self::deliver($user, new SiakadNotice($kind, $title, $body, $link, $icon, $group)), $title, $body, $group);
        }
    }

    /**
     * Send a scheduled reminder at most once per person and key.
     */
    public static function remindOnce(User $user, string $key, string $kind, string $title, string $body, string $url, string $icon): bool
    {
        if (! $user->is_active || SentReminder::query()->where('user_id', $user->id)->where('key', $key)->exists()) {
            return false;
        }

        SentReminder::create(['user_id' => $user->id, 'key' => $key, 'created_at' => now()]);
        self::push($user, self::deliver($user, new SiakadNotice($kind, $title, $body, self::path($url), $icon)), $title, $body);

        return true;
    }

    /**
     * Links are stored as paths ("/mahasantri/bills"), not full URLs: reminders are
     * created by the scheduler on the command line, where route() would use APP_URL
     * instead of the address people actually open.
     */
    public static function path(string $url): string
    {
        $parts = parse_url($url);
        $path = '/'.ltrim($parts['path'] ?? '', '/');

        return isset($parts['query']) ? $path.'?'.$parts['query'] : $path;
    }

    /**
     * Store the notification and return its id.
     */
    private static function deliver(User $user, SiakadNotice $notice): string
    {
        // Set beforehand so the database row and the pop-up share the id.
        $notice->id = (string) Str::uuid();
        $user->notify($notice);

        return $notice->id;
    }

    /**
     * The pop-up opens the notification (marking it read) like a click on the bell.
     * One pop-up per group: a newer message replaces the older one on the device.
     */
    private static function push(User $user, string $id, string $title, string $body, ?string $group = null): void
    {
        if (! WebPush::enabled() || ! $user->pushSubscriptions()->exists()) {
            return;
        }

        SendWebPush::dispatchAfterResponse($user->id, [
            'title' => $title,
            'body' => $body,
            'url' => route('notifications.open', $id, false),
            'tag' => $group ?? $id,
        ]);
    }

    private static function bumpUnread(User $user, string $group, string $title, string $countedBody, string $url): ?DatabaseNotification
    {
        // A user has few unread notifications; matching in PHP keeps this portable (no JSON queries).
        $existing = $user->unreadNotifications()
            ->where('type', SiakadNotice::class)
            ->latest()
            ->limit(50)
            ->get()
            ->first(fn (DatabaseNotification $notification) => ($notification->data['group'] ?? null) === $group);

        if ($existing === null) {
            return null;
        }

        $count = (int) ($existing->data['count'] ?? 1) + 1;

        $existing->forceFill([
            'data' => [...$existing->data, 'title' => $title, 'body' => str_replace(':count', (string) $count, $countedBody), 'url' => $url, 'count' => $count],
            'created_at' => now(),
        ])->save();

        return $existing;
    }
}
