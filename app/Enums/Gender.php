<?php

namespace App\Enums;

enum Gender: string
{
    case Male = 'male';
    case Female = 'female';

    public function label(): string
    {
        return match ($this) {
            self::Male => __('Male'),
            self::Female => __('Female'),
        };
    }

    /**
     * The class (and pesantren) term: Putra for male classes, Putri for female ones.
     */
    public function classLabel(): string
    {
        return match ($this) {
            self::Male => __('Putra'),
            self::Female => __('Putri'),
        };
    }

    /**
     * Read the values used in import spreadsheets (L/P, male/female).
     */
    public static function fromImport(mixed $value): ?self
    {
        return match (mb_strtolower(trim((string) $value))) {
            'l', 'male', 'laki-laki', 'laki laki' => self::Male,
            'p', 'female', 'perempuan' => self::Female,
            default => null,
        };
    }
}
