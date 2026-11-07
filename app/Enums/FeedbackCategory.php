<?php

namespace App\Enums;

enum FeedbackCategory: string
{
    case Academic = 'academic';
    case Finance = 'finance';
    case App = 'app';
    case Facilities = 'facilities';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Academic => __('Academic services'),
            self::Finance => __('Finance'),
            self::App => __('SIAKAD app'),
            self::Facilities => __('Facilities'),
            self::Other => __('Other'),
        };
    }
}
