<?php

namespace App\Enums;

enum TeachingDocumentStatus: string
{
    case Waiting = 'waiting';
    case Accepted = 'accepted';
    case Returned = 'returned';

    public function label(): string
    {
        return match ($this) {
            self::Waiting => __('Waiting for review'),
            self::Accepted => __('Accepted'),
            self::Returned => __('Returned'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Waiting => 'amber',
            self::Accepted => 'green',
            self::Returned => 'red',
        };
    }
}
