<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Enums\TeachingDocumentKind;
use App\Enums\TeachingDocumentStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One uploaded version of a muhadhir's RPS or signed journal recap, for a
 * class or joint group (its lead offering). Never replaced: a new upload is a
 * new version.
 *
 * @property int $id
 * @property int $course_offering_id
 * @property TeachingDocumentKind $kind
 * @property int $version
 * @property string $file_path
 * @property string $original_name
 * @property int $uploaded_by
 * @property TeachingDocumentStatus $status
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property string|null $review_note
 * @property Carbon $created_at
 * @property-read CourseOffering $courseOffering
 * @property-read User $uploader
 * @property-read User|null $reviewer
 */
#[Fillable(['course_offering_id', 'kind', 'version', 'file_path', 'original_name', 'uploaded_by', 'status', 'reviewed_by', 'reviewed_at', 'review_note'])]
class TeachingDocument extends Model
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
            'kind' => TeachingDocumentKind::class,
            'status' => TeachingDocumentStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<CourseOffering, $this>
     */
    public function courseOffering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
