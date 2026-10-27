<?php

namespace App\Enums;

enum ExamType: string
{
    case Midterm = 'uts';
    case Final = 'uas';

    public function label(): string
    {
        return match ($this) {
            self::Midterm => __('UTS (midterm exam)'),
            self::Final => __('UAS (final exam)'),
        };
    }

    public function short(): string
    {
        return match ($this) {
            self::Midterm => 'UTS',
            self::Final => 'UAS',
        };
    }
}
