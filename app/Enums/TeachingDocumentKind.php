<?php

namespace App\Enums;

enum TeachingDocumentKind: string
{
    case Rps = 'rps';
    case Journal = 'journal';

    public function label(): string
    {
        return match ($this) {
            self::Rps => __('RPS (semester lesson plan)'),
            self::Journal => __('Teaching journal (signed recap)'),
        };
    }

    public function short(): string
    {
        return match ($this) {
            self::Rps => 'RPS',
            self::Journal => __('Journal'),
        };
    }
}
