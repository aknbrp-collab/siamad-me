<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $quiz_attempt_id
 * @property int $quiz_question_id
 * @property list<int>|null $selected_option_ids
 * @property string|null $text
 * @property string|null $points_awarded
 * @property-read QuizQuestion $question
 */
#[Fillable(['quiz_attempt_id', 'quiz_question_id', 'selected_option_ids', 'text', 'points_awarded'])]
class QuizAnswer extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'selected_option_ids' => 'array',
            'points_awarded' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<QuizQuestion, $this>
     */
    public function question(): BelongsTo
    {
        return $this->belongsTo(QuizQuestion::class, 'quiz_question_id');
    }
}
