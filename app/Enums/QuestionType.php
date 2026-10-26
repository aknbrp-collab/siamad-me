<?php

namespace App\Enums;

enum QuestionType: string
{
    case SingleChoice = 'single_choice';
    case MultipleChoice = 'multiple_choice';
    case TrueFalse = 'true_false';
    case Essay = 'essay';

    public function label(): string
    {
        return match ($this) {
            self::SingleChoice => __('Multiple choice (one answer)'),
            self::MultipleChoice => __('Multiple choice (several answers)'),
            self::TrueFalse => __('True / false'),
            self::Essay => __('Essay (graded by the ustadz)'),
        };
    }

    public function hasOptions(): bool
    {
        return $this !== self::Essay;
    }
}
