<?php

namespace App\Enums;

enum DocumentStatus: string
{
    case Missing = 'missing';
    case Uploaded = 'uploaded';
    case Verified = 'verified';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Missing => __('Missing'),
            self::Uploaded => __('Awaiting verification'),
            self::Verified => __('Verified'),
            self::Rejected => __('Rejected'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Missing => 'zinc',
            self::Uploaded => 'blue',
            self::Verified => 'green',
            self::Rejected => 'red',
        };
    }

    /**
     * Whether the owner still has something to do for this document.
     */
    public function needsOwnerAction(): bool
    {
        return in_array($this, [self::Missing, self::Rejected], true);
    }
}
