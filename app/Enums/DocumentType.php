<?php

namespace App\Enums;

enum DocumentType: string
{
    case Ktp = 'ktp';
    case Kk = 'kk';
    case Ijazah = 'ijazah';

    public function label(): string
    {
        return match ($this) {
            self::Ktp => __('KTP (identity card)'),
            self::Kk => __('KK (family card)'),
            self::Ijazah => __('Ijazah (diploma)'),
        };
    }
}
