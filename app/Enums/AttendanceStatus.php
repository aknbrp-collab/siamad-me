<?php

namespace App\Enums;

enum AttendanceStatus: string
{
    case Present = 'hadir';
    case Excused = 'izin';
    case Sick = 'sakit';
    case Absent = 'alpa';

    public function label(): string
    {
        return match ($this) {
            self::Present => __('Hadir'),
            self::Excused => __('Izin'),
            self::Sick => __('Sakit'),
            self::Absent => __('Alpa'),
        };
    }

    public function short(): string
    {
        return match ($this) {
            self::Present => 'H',
            self::Excused => 'I',
            self::Sick => 'S',
            self::Absent => 'A',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Present => 'green',
            self::Excused => 'blue',
            self::Sick => 'amber',
            self::Absent => 'red',
        };
    }
}
