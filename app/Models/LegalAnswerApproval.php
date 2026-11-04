<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A muhadhir's approval of an answer version.
 *
 * @property int $id
 * @property int $legal_answer_id
 * @property int $approver_id
 * @property Carbon $created_at
 * @property-read User $approver
 */
#[Fillable(['legal_answer_id', 'approver_id'])]
class LegalAnswerApproval extends Model
{
    use Auditable;

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }
}
