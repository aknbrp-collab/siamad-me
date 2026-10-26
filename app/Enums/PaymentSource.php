<?php

namespace App\Enums;

/**
 * Who entered a payment: the finance office, or the mahasantri / wali santri
 * confirming their own bank transfer (with the bank receipt).
 */
enum PaymentSource: string
{
    case Staff = 'staff';
    case Payer = 'payer';

    public function label(): string
    {
        return match ($this) {
            self::Staff => __('Recorded by the finance office'),
            self::Payer => __('Sent by mahasantri / wali'),
        };
    }
}
