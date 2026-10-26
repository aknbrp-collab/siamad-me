<?php

namespace App\Listeners;

use App\Models\User;
use App\Support\ActiveRole;
use Illuminate\Auth\Events\Login;

class RecordSuccessfulLogin
{
    public function handle(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $event->user->forceFill(['last_login_at' => now()])->saveQuietly();

        // Every login starts without a role so multi-role users choose again.
        ActiveRole::forget();
    }
}
