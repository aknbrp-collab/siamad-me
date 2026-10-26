<?php

namespace App\Concerns;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Shared behaviour of student_documents and teacher_documents.
 *
 * @property DocumentType $type
 * @property string|null $file_path
 * @property string|null $original_name
 * @property DocumentStatus $status
 * @property Carbon|null $uploaded_at
 * @property int|null $verified_by
 * @property Carbon|null $verified_at
 * @property string|null $rejection_note
 */
trait IsPersonalDocument
{
    use Auditable;

    public function initializeIsPersonalDocument(): void
    {
        $this->mergeFillable(['type', 'file_path', 'original_name', 'status', 'uploaded_at', 'verified_by', 'verified_at', 'rejection_note']);

        $this->mergeCasts([
            'type' => DocumentType::class,
            'status' => DocumentStatus::class,
            'uploaded_at' => 'datetime',
            'verified_at' => 'datetime',
        ]);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
