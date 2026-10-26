<?php

namespace App\Enums;

enum ScholarshipType: string
{
    case Percent = 'percent';
    case Fixed = 'fixed';
    case Full = 'full';

    public function label(): string
    {
        return match ($this) {
            self::Percent => __('Percentage of the bill'),
            self::Fixed => __('Fixed amount'),
            self::Full => __('Full waiver'),
        };
    }
}
