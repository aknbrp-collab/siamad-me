<?php

namespace App\Enums;

/**
 * The level of an ijazah (v4 D22). Mahasantri bring the diploma they enter
 * with; muhadhir add one per degree.
 */
enum EducationLevel: string
{
    case Ma = 'ma';
    case Pesantren = 'pesantren';
    case S1 = 's1';
    case S2 = 's2';
    case S3 = 's3';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Ma => __('MA / SMA or equivalent'),
            self::Pesantren => __('Pesantren (mu\'adalah)'),
            self::S1 => __('S1 (bachelor)'),
            self::S2 => __('S2 (master)'),
            self::S3 => __('S3 (doctorate)'),
            self::Other => __('Other'),
        };
    }

    /**
     * The levels a mahasantri can enter with.
     *
     * @return list<self>
     */
    public static function forStudents(): array
    {
        return [self::Ma, self::Pesantren, self::Other];
    }
}
