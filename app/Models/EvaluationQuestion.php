<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A question scored 1–5 in a semester's muhadhir evaluation (v6 D35).
 *
 * @property int $id
 * @property int $semester_id
 * @property string $group e.g. Penguasaan materi
 * @property string $question
 * @property int $position
 * @property bool $is_active
 * @property-read Semester $semester
 */
#[Fillable(['semester_id', 'group', 'question', 'position', 'is_active'])]
class EvaluationQuestion extends Model
{
    use Auditable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'position' => 'integer'];
    }

    /**
     * @return BelongsTo<Semester, $this>
     */
    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class);
    }

    /**
     * @return HasMany<EvaluationScore, $this>
     */
    public function scores(): HasMany
    {
        return $this->hasMany(EvaluationScore::class);
    }
}
