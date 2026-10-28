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
    case CashReport = 'cash_report';
    case Letter = 'letter';

    public function label(): string
    {
        return match ($this) {
            self::Khs => __('KHS'),
            self::Transcript => __('Transcript'),
            self::ExamCard => __('Exam card'),
            self::Receipt => __('Payment receipt'),
            self::CashReport => __('Cash report'),
            self::Letter => __('Official letter'),
        };
    }

    /**
     * Documents with a default signatory and stamp in Settings. A letter takes
     * them from its letter type instead.
     *
     * @return list<self>
     */
    public static function withDefaults(): array
    {
        return [self::Khs, self::Transcript, self::ExamCard, self::Receipt, self::CashReport];
    }

    /**
     * Printed above the blank signature line when no signatory is set.
     */
    public function fallbackTitle(): string
    {
        return match ($this) {
            self::Receipt, self::CashReport => 'Bagian Keuangan',
            self::Letter => 'Mudir',
            default => 'Bagian Akademik',
        };
    }
}
