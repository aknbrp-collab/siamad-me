<?php

namespace App\Jobs;

use App\Models\User;
use App\Support\FirebasePush;
use Illuminate\Foundation\Bus\Dispatchable;
use Throwable;

/**
 * Pushes one notification to a user's mobile app devices (v6 D39). Like Web
 * Push, dispatched after the response, so Firebase never delays the page.
 */
class SendFirebasePush
{
    use Dispatchable;

    /**
     * @param  array{title: string, body: string, link: string, notification_id: string, tag: string}  $payload
     */
    public function __construct(public int $userId, public array $payload) {}

    public function handle(FirebasePush $push): void
    {
        $user = User::query()->find($this->userId);

        if ($user === null || ! $user->is_active) {
            return;
        }

        try {
            $push->send($user, $this->payload);
        } catch (Throwable $exception) {
            // The notification is already in the bell; a failed push is only logged.
            report($exception);
        }
    }
}
