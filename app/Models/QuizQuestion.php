<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Enums\QuestionType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $quiz_id
 * @property QuestionType $type
 * @property string $body
 * @property int $points
 * @property int $position
 * @property-read Quiz $quiz
 * @property-read Collection<int, QuizOption> $options
 */
#[Fillable(['quiz_id', 'type', 'body', 'points', 'position'])]
class QuizQuestion extends Model
{
    use Auditable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => QuestionType::class,
            'points' => 'integer',
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Quiz, $this>
     */
    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }

    /**
     * @return HasMany<QuizOption, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(QuizOption::class)->orderBy('position')->orderBy('id');
    }
}
