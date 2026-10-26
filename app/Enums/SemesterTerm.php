<?php

namespace App\Enums;

enum SemesterTerm: string
{
    case Odd = 'odd';
    case Even = 'even';

    public function label(): string
    {
        return match ($this) {
            self::Odd => __('Odd (Ganjil)'),
            self::Even => __('Even (Genap)'),
        };
    }
}
