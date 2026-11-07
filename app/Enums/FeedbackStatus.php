<?php

namespace App\Enums;

enum FeedbackStatus: string
{
    case New = 'new';
    case InProgress = 'in_progress';
    case Done = 'done';

    public function label(): string
    {
        return match ($this) {
            self::New => __('New'),
            self::InProgress => __('Being handled'),
            self::Done => __('Done'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::New => 'amber',
            self::InProgress => 'blue',
            self::Done => 'green',
        };
    }
}
