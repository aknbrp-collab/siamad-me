<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An issued outgoing letter. Never edited: a wrong letter is cancelled with a
 * reason (its number stays in the register) and a new one is issued.
 *
 * @property int $id
 * @property string $number
 * @property int $year
 * @property int $sequence
 * @property int $letter_type_id
 * @property int|null $document_template_id The Word template version it was issued with (null: the built-in PDF layout)
 * @property int|null $student_id
 * @property int|null $teacher_id
 * @property int|null $letter_request_id
 * @property string|null $recipient
 * @property string $subject_line
 * @property string $body
 * @property Carbon $issued_on
 * @property int $issued_by
 * @property int|null $signatory_id
 * @property int|null $stamp_id
 * @property bool $print_marks
 * @property Carbon|null $cancelled_at
 * @property int|null $cancelled_by
 * @property string|null $cancel_reason
 * @property-read LetterType $type
 * @property-read DocumentTemplate|null $documentTemplate
 * @property-read Student|null $student
 * @property-read Teacher|null $teacher
 * @property-read User $issuer
 * @property-read Signatory|null $signatory
 * @property-read Stamp|null $stamp
 */
#[Fillable([
    'number', 'year', 'sequence', 'letter_type_id', 'document_template_id', 'student_id', 'teacher_id', 'letter_request_id', 'recipient', 'subject_line',
    'body', 'issued_on', 'issued_by', 'signatory_id', 'stamp_id', 'print_marks', 'cancelled_at', 'cancelled_by', 'cancel_reason',
])]
class Letter extends Model
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
            'issued_on' => 'date',
            'print_marks' => 'boolean',
            'cancelled_at' => 'datetime',
        ];
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    /**
     * Whom the letter is about, for lists.
     */
    public function subjectName(): ?string
    {
        return $this->student?->user->name ?? $this->teacher?->user->name;
    }

    /**
     * @return BelongsTo<DocumentTemplate, $this>
     */
    public function documentTemplate(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplate::class);
    }

    /**
     * @return BelongsTo<LetterType, $this>
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(LetterType::class, 'letter_type_id');
    }

    /**
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * @return BelongsTo<Teacher, $this>
     */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /**
     * @return BelongsTo<Signatory, $this>
     */
    public function signatory(): BelongsTo
    {
        return $this->belongsTo(Signatory::class);
    }

    /**
     * @return BelongsTo<Stamp, $this>
     */
    public function stamp(): BelongsTo
    {
        return $this->belongsTo(Stamp::class);
    }
}
