<?php

namespace App\Enums;

enum HandoverStatus: string
{
    case Submitted = 'submitted';
    case Verified = 'verified';
    case PartiallyRejected = 'partially_rejected';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Submitted => __('Awaiting Admin'),
            self::Verified => __('Verified'),
            self::PartiallyRejected => __('Partly rejected'),
            self::Rejected => __('Rejected'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Submitted => 'amber',
            self::Verified => 'green',
            self::PartiallyRejected => 'orange',
            self::Rejected => 'red',
        };
    }
}
