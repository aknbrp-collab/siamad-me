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
    /** Recorded by a Petugas Pembayaran (v2), not by the finance office. */
    case Collector = 'collector';
    /** Entered from the paper archive of a previous year (v3, maintenance mode). */
    case Import = 'import';

    public function label(): string
    {
        return match ($this) {
            self::Staff => __('Recorded by the finance office'),
            self::Payer => __('Sent by mahasantri / wali'),
            self::Collector => __('Recorded by a payment collector'),
            self::Import => __('Entered from the archive'),
        };
    }
}
