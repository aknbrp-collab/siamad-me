<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A Tanya Hukum question forwarded by the CMS (v5 D30, spec §4.1). The asker's
 * email is never received; their name only when they are not anonymous.
 *
 * @property int $id
 * @property string $cms_question_id
 * @property string|null $tracking_code
 * @property string $category ibadah | muamalah | munakahat | mawaris | jinayah | akhlak | lainnya
 * @property string $question
 * @property string|null $asker_name
 * @property string|null $asker_city
 * @property bool $anonymous
 * @property Carbon|null $submitted_at
 * @property string|null $moderator_note
 * @property string $callback_url
 * @property int|null $discussion_id
 * @property Carbon $created_at
 * @property-read Discussion|null $discussion
 */
#[Fillable(['cms_question_id', 'tracking_code', 'category', 'question', 'asker_name', 'asker_city', 'anonymous', 'submitted_at', 'moderator_note', 'callback_url', 'discussion_id'])]
class LegalQuestion extends Model
{
    use Auditable;

    public const CATEGORIES = ['ibadah', 'muamalah', 'munakahat', 'mawaris', 'jinayah', 'akhlak', 'lainnya'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['anonymous' => 'boolean', 'submitted_at' => 'datetime'];
    }

    /**
     * The id SIAKAD gives the CMS, e.g. SIAKAD-Q-000123.
     */
    public function publicId(): string
    {
        return sprintf('SIAKAD-Q-%06d', $this->id);
    }

    public function categoryLabel(): string
    {
        return ucfirst($this->category);
    }

    public function askerLabel(): string
    {
        return $this->anonymous || $this->asker_name === null
            ? __('Anonymous').($this->asker_city ? ', '.$this->asker_city : '')
            : $this->asker_name.($this->asker_city ? ', '.$this->asker_city : '');
    }

    /**
     * @return BelongsTo<Discussion, $this>
     */
    public function discussion(): BelongsTo
    {
        return $this->belongsTo(Discussion::class);
    }

    /**
     * @return HasMany<LegalAnswer, $this>
     */
    public function answers(): HasMany
    {
        return $this->hasMany(LegalAnswer::class);
    }
}
