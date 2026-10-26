<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * An in-app notification (the bell). The text is stored already translated,
 * in the locale of the moment it is sent. `group` lets several events about
 * the same thing (a busy forum thread) share one unread notification.
 */
class SiakadNotice extends Notification
{
    use Queueable;

    public function __construct(
        public string $kind,
        public string $title,
        public string $body,
        public string $url,
        public string $icon,
        public ?string $group = null,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array{kind: string, title: string, body: string, url: string, icon: string, group: string|null, count: int}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => $this->kind,
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->url,
            'icon' => $this->icon,
            'group' => $this->group,
            'count' => 1,
        ];
    }
}
