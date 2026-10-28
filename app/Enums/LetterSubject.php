<?php

namespace App\Enums;

/**
 * Whom a letter type is about; decides which placeholders are filled.
 */
enum LetterSubject: string
{
    case None = 'none';
    case Student = 'student';
    case Teacher = 'teacher';

    public function label(): string
    {
        return match ($this) {
            self::None => __('Nobody in particular'),
            self::Student => __('A mahasantri'),
            self::Teacher => __('A muhadhir'),
        };
    }
}
