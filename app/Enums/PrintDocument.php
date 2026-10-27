<?php

namespace App\Enums;

/**
 * Printed documents that carry a signature block. Each has a default
 * signatory and stamp (Settings → Signatures & stamps).
 */
enum PrintDocument: string
{
    case Khs = 'khs';
    case Transcript = 'transcript';
    case ExamCard = 'exam_card';
    case Receipt = 'receipt';

    public function label(): string
    {
        return match ($this) {
            self::Khs => __('KHS'),
            self::Transcript => __('Transcript'),
            self::ExamCard => __('Exam card'),
            self::Receipt => __('Payment receipt'),
        };
    }

    /**
     * Printed above the blank signature line when no signatory is set.
     */
    public function fallbackTitle(): string
    {
        return match ($this) {
            self::Receipt => 'Bagian Keuangan',
            default => 'Bagian Akademik',
        };
    }
}
