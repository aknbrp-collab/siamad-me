<?php

namespace App\Enums;

enum StudentStatus: string
{
    case Active = 'active';
    case Leave = 'leave';
    case Graduated = 'graduated';
    case DroppedOut = 'dropped_out';

    public function label(): string
    {
        return match ($this) {
            self::Active => __('Active'),
            self::Leave => __('On leave'),
            self::Graduated => __('Graduated'),
            self::DroppedOut => __('Dropped out'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'green',
            self::Leave => 'amber',
            self::Graduated => 'blue',
            self::DroppedOut => 'red',
        };
    }
}
