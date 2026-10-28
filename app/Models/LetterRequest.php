<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Enums\LetterRequestStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A mahasantri asking for a letter (e.g. Surat Keterangan Aktif Kuliah).
 *
 * @property int $id
 * @property int $student_id
 * @property int $letter_type_id
 * @property string $purpose
 * @property LetterRequestStatus $status
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property string|null $reject_reason
 * @property Carbon $created_at
 * @property-read Student $student
 * @property-read LetterType $type
 * @property-read Letter|null $letter
 */
#[Fillable(['student_id', 'letter_type_id', 'purpose', 'status', 'decided_by', 'decided_at', 'reject_reason'])]
class LetterRequest extends Model
{
    use Auditable;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => LetterRequestStatus::class,
            'decided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * @return BelongsTo<LetterType, $this>
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(LetterType::class, 'letter_type_id');
    }

    /**
     * @return HasOne<Letter, $this>
     */
    public function letter(): HasOne
    {
        return $this->hasOne(Letter::class);
    }
}
