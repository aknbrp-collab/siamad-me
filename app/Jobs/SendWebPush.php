<?php

namespace App\Jobs;

use App\Models\User;
use App\Support\WebPush;
use Illuminate\Foundation\Bus\Dispatchable;
use Throwable;

/**
 * Pushes one notification to a user's devices. Dispatched after the response
 * (no queue worker needed), so a slow push service never delays the page.
 */
class SendWebPush
{
    use Dispatchable;

    /**
     * @param  array{title: string, body: string, url: string, tag: string}  $payload
     */
    public function __construct(public int $userId, public array $payload) {}

    public function handle(WebPush $push): void
    {
        $user = User::query()->find($this->userId);

        if ($user === null || ! $user->is_active) {
            return;
        }

        try {
            $push->send($user, $this->payload);
        } catch (Throwable $exception) {
            // The notification is already in the bell; a failed pop-up is only logged.
            report($exception);
        }
    }
}
