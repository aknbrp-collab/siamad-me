<?php

namespace App\Enums;

enum SemesterStatus: string
{
    case Planned = 'planned';
    case Active = 'active';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Planned => __('Planned'),
            self::Active => __('Active'),
            self::Closed => __('Closed'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Planned => 'zinc',
            self::Active => 'green',
            self::Closed => 'amber',
        };
    }
}
