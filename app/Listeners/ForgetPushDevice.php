<?php

namespace App\Listeners;

use App\Models\PushSubscription;
use Illuminate\Auth\Events\Logout;

/**
 * Logging out stops Web Push pop-ups on that browser for the person leaving.
 */
class ForgetPushDevice
{
    public function handle(Logout $event): void
    {
        $endpoint = session('push_endpoint');

        if ($event->user === null || ! is_string($endpoint)) {
            return;
        }

        PushSubscription::query()
            ->where('user_id', $event->user->getAuthIdentifier())
            ->where('endpoint', $endpoint)
            ->delete();
    }
}
