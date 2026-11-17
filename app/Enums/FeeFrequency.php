<?php

namespace App\Enums;

enum FeeFrequency: string
{
    case PerSemester = 'per_semester';
    case Monthly = 'monthly';
    case Once = 'once';

    public function label(): string
    {
        return match ($this) {
            self::PerSemester => __('Per semester'),
            self::Monthly => __('Monthly (months per semester set on the fee)'),
            self::Once => __('Once per mahasantri'),
        };
    }
}
