<?php

namespace App\Enums;

enum ArticleStatus: string
{
    case Draft = 'draft';
    case InReview = 'in_review';
    case ChangesRequested = 'changes_requested';
    case Published = 'published';
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Draft'),
            self::InReview => __('In review'),
            self::ChangesRequested => __('Changes requested'),
            self::Published => __('Published'),
            self::Withdrawn => __('Withdrawn'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'zinc',
            self::InReview => 'blue',
            self::ChangesRequested => 'amber',
            self::Published => 'green',
            self::Withdrawn => 'red',
        };
    }
}
