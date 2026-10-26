<?php

namespace App\Enums;

enum MeetingProvider: string
{
    case Zoom = 'zoom';
    case GoogleMeet = 'google_meet';
    case Jitsi = 'jitsi';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Zoom => 'Zoom',
            self::GoogleMeet => 'Google Meet',
            self::Jitsi => 'Jitsi',
            self::Other => __('Other'),
        };
    }
}
