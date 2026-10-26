<?php

namespace App\Enums;

enum MeetingMode: string
{
    case Offline = 'offline';
    case Online = 'online';

    public function label(): string
    {
        return match ($this) {
            self::Offline => __('In class'),
            self::Online => __('Online'),
        };
    }
}
