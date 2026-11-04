<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A muhadhir's decision on a mahasantri's article in one review round.
 *
 * @property int $id
 * @property int $article_id
 * @property int $round
 * @property int $reviewer_id
 * @property string $decision approved | changes
 * @property string|null $note
 * @property Carbon $created_at
 * @property-read User $reviewer
 */
#[Fillable(['article_id', 'round', 'reviewer_id', 'decision', 'note'])]
class ArticleReview extends Model
{
    use Auditable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['round' => 'integer'];
    }

    public function approved(): bool
    {
        return $this->decision === 'approved';
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
