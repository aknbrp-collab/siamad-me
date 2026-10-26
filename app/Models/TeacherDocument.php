<?php

namespace App\Models;

use App\Concerns\IsPersonalDocument;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $teacher_id
 * @property-read Teacher $teacher
 */
class TeacherDocument extends Model
{
    use IsPersonalDocument;

    /**
     * @return BelongsTo<Teacher, $this>
     */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }
}
