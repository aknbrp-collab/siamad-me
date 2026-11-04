<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One version of the answer to a Tanya Hukum question (v5 D30). Sent once two
 * muhadhir other than the drafter approve it; a correction is a new version.
 *
 * @property int $id
 * @property int $legal_question_id
 * @property int $version
 * @property string $answer_md
 * @property string|null $references
 * @property int $drafted_by
 * @property string $status draft | awaiting | approved | sent | superseded
 * @property Carbon|null $submitted_at
 * @property Carbon|null $sent_at
 * @property string|null $cms_status answered | published
 * @property bool $correction_pending
 * @property string|null $error
 * @property Carbon $updated_at
 * @property-read LegalQuestion $question
 * @property-read User $drafter
 */
#[Fillable(['legal_question_id', 'version', 'answer_md', 'references', 'drafted_by', 'status', 'submitted_at', 'sent_at', 'cms_status', 'correction_pending', 'error'])]
class LegalAnswer extends Model
{
    use Auditable;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
        'correction_pending' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'submitted_at' => 'datetime',
            'sent_at' => 'datetime',
            'correction_pending' => 'boolean',
        ];
    }

    public function html(): string
    {
        return Article::render($this->answer_md);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'draft' => __('Draft'),
            'awaiting' => __('Waiting for approval'),
            'approved' => __('Approved, being sent'),
            'sent' => $this->correction_pending ? __('Sent; correction waits for the moderator') : ($this->cms_status === 'published' ? __('Sent and published') : __('Sent')),
            default => __('Replaced by a correction'),
        };
    }

    /**
     * @return BelongsTo<LegalQuestion, $this>
     */
    public function question(): BelongsTo
    {
        return $this->belongsTo(LegalQuestion::class, 'legal_question_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function drafter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'drafted_by');
    }

    /**
     * @return HasMany<LegalAnswerApproval, $this>
     */
    public function approvals(): HasMany
    {
        return $this->hasMany(LegalAnswerApproval::class);
    }
}
