<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * How many mahasantri gave a score to a question for a class kuliah (v6 D35).
 * Only counts are kept, so no answer can be traced to a mahasantri. Not
 * audited, for the same reason.
 *
 * @property int $id
 * @property int $course_offering_id
 * @property int $evaluation_question_id
 * @property int $score 1–5
 * @property int $count
 */
class EvaluationScore extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['score' => 'integer', 'count' => 'integer'];
    }
}
