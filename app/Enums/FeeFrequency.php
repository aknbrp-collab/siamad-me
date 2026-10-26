<?php

namespace App\Enums;

enum FeeFrequency: string
{
    case PerSemester = 'per_semester';
    case Monthly = 'monthly';
    case Once = 'once';

    /** Months billed per semester for monthly fees. */
    public const int MONTHS_PER_SEMESTER = 6;

    public function label(): string
    {
        return match ($this) {
            self::PerSemester => __('Per semester'),
            self::Monthly => __('Monthly (billed 6× per semester)'),
            self::Once => __('Once per mahasantri'),
        };
    }
}
