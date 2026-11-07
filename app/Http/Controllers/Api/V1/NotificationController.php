<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\NotificationResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * The bell (v6 D39). Until push through Firebase is switched on, the app
 * checks this when it opens and every few minutes.
 *
 * @tags Notifications
 */
class NotificationController extends Controller
{
    /**
     * Notifications, newest first.
     *
     * `meta.unread` is the number of unread notifications.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['unread' => ['nullable', 'boolean'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $user = $request->user();

        $notifications = ($request->boolean('unread') ? $user->unreadNotifications() : $user->notifications())
            ->paginate($request->integer('per_page', 20));

        return NotificationResource::collection($notifications)->additional([
            'meta' => ['unread' => $user->unreadNotifications()->count()],
        ]);
    }

    /**
     * Mark one read.
     */
    public function read(Request $request, string $notification): Response
    {
        $request->user()->notifications()->findOrFail($notification)->markAsRead();

        return response()->noContent();
    }

    /**
     * Mark all read.
     */
    public function readAll(Request $request): Response
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->noContent();
    }
}
