<?php

namespace App\Enums;

enum DiscussionKind: string
{
    /** Bimbingan penelitian dan penulisan: one lead muhadhir, chosen mahasantri (v5 D25). */
    case Bimbingan = 'bimbingan';

    /** LPPPK channels: every active mahasantri and muhadhir (v5 D28). */
    case Lpppk = 'lpppk';

    /** The discussion of one Tanya Hukum question, open like LPPPK (v5 D30). */
    case TanyaHukum = 'tanya_hukum';

    public function label(): string
    {
        return match ($this) {
            self::Bimbingan => __('Bimbingan'),
            self::Lpppk => __('LPPPK'),
            self::TanyaHukum => __('Tanya Hukum'),
        };
    }
}
