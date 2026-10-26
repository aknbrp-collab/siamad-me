<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $course_offering_id
 * @property string $title
 * @property string|null $body
 * @property string|null $url
 * @property string|null $file_path
 * @property string|null $original_name
 * @property Carbon $created_at
 * @property-read CourseOffering $courseOffering
 */
#[Fillable(['course_offering_id', 'title', 'body', 'url', 'file_path', 'original_name'])]
class Material extends Model
{
    use Auditable;

    /**
     * @return BelongsTo<CourseOffering, $this>
     */
    public function courseOffering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class);
    }
}
