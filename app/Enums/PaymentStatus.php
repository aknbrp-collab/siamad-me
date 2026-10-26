<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Unverified = 'unverified';
    case Verified = 'verified';
    case Rejected = 'rejected';
    case Void = 'void';

    public function label(): string
    {
        return match ($this) {
            self::Unverified => __('Pending verification'),
            self::Verified => __('Verified'),
            self::Rejected => __('Rejected'),
            self::Void => __('Void'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Unverified => 'amber',
            self::Verified => 'green',
            self::Rejected => 'red',
            self::Void => 'zinc',
        };
    }

    /**
     * Statuses that use up an instalment and reserve part of the balance.
     *
     * @return list<self>
     */
    public static function counting(): array
    {
        return [self::Unverified, self::Verified];
    }
}
