<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Enums\LetterSubject;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A kind of outgoing letter: its code (in the number), whom it is about, its
 * template, and who signs it by default.
 *
 * @property int $id
 * @property string $name
 * @property string $code
 * @property LetterSubject $subject
 * @property bool $requestable
 * @property string $template
 * @property int|null $signatory_id
 * @property int|null $stamp_id
 * @property bool $print_marks
 * @property bool $is_active
 * @property-read Signatory|null $signatory
 * @property-read Stamp|null $stamp
 */
#[Fillable(['name', 'code', 'subject', 'requestable', 'template', 'signatory_id', 'stamp_id', 'print_marks', 'is_active'])]
class LetterType extends Model
{
    use Auditable;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'subject' => 'none',
        'requestable' => false,
        'print_marks' => true,
        'is_active' => true,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subject' => LetterSubject::class,
            'requestable' => 'boolean',
            'print_marks' => 'boolean',
            'is_active' => 'boolean',
        ];
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
