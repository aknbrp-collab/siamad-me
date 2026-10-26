<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class NotificationController
{
    /**
     * Mark one of the user's own notifications read and go to what it is about.
     */
    public function __invoke(Request $request, string $id): RedirectResponse
    {
        $notification = $request->user()->notifications()->findOrFail($id);
        $notification->markAsRead();

        $path = (string) ($notification->data['url'] ?? '');

        // Stored as a path inside this application; anything else (e.g. "//host") goes to the list.
        return str_starts_with($path, '/') && ! str_starts_with($path, '//')
            ? redirect()->to($path)
            : redirect()->route('notifications');
    }
}
