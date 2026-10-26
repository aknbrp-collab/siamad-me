<?php

namespace App\Enums;

enum ActivityCategory: string
{
    case Halaqah = 'halaqah';
    case Tahfidz = 'tahfidz';
    case Kajian = 'kajian';
    case Ibadah = 'ibadah';
    case Discipline = 'kedisiplinan';
    case Other = 'lainnya';

    public function label(): string
    {
        return match ($this) {
            self::Halaqah => __('Halaqah'),
            self::Tahfidz => __('Tahfidz (setoran hafalan)'),
            self::Kajian => __('Kajian'),
            self::Ibadah => __('Ibadah'),
            self::Discipline => __('Discipline'),
            self::Other => __('Other'),
        };
    }
}
