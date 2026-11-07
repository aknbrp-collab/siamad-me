<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;

/**
 * A notification from the bell (v6 D39). `link` is the page in SIAKAD it is
 * about (a path); the app maps it to its own screen or opens it on the web.
 *
 * @mixin DatabaseNotification
 */
class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var DatabaseNotification $notification */
        $notification = $this->resource;
        $data = $notification->data;

        return [
            'id' => $notification->id,
            'kind' => (string) ($data['kind'] ?? ''),
            'title' => (string) ($data['title'] ?? ''),
            'body' => (string) ($data['body'] ?? ''),
            'link' => (string) ($data['url'] ?? ''),
            'icon' => (string) ($data['icon'] ?? ''),
            /** Grouped notifications (e.g. new messages in one room) count how many they stand for. */
            'count' => (int) ($data['count'] ?? 1),
            'read' => $notification->read_at !== null,
            'created_at' => $notification->created_at?->toIso8601String(),
        ];
    }
}
