<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * When mahasantri evaluate their muhadhir in a semester (v6 D35).
 *
 * @property int $id
 * @property int $semester_id
 * @property Carbon $opens_on
 * @property Carbon $closes_on
 * @property string $comment_prompt
 * @property bool $required_for_khs
 * @property-read Semester $semester
 */
#[Fillable(['semester_id', 'opens_on', 'closes_on', 'comment_prompt', 'required_for_khs'])]
class EvaluationPeriod extends Model
{
    use Auditable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['opens_on' => 'date', 'closes_on' => 'date', 'required_for_khs' => 'boolean'];
    }

    /**
     * @return BelongsTo<Semester, $this>
     */
    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class);
    }

    public function isOpen(): bool
    {
        return today()->betweenIncluded($this->opens_on, $this->closes_on);
    }

    public function hasClosed(): bool
    {
        return today()->gt($this->closes_on);
    }

    public function rangeLabel(): string
    {
        return $this->opens_on->translatedFormat('j M Y').' – '.$this->closes_on->translatedFormat('j M Y');
    }
}
