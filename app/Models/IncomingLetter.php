<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A letter received (surat masuk), with its agenda number and disposition.
 *
 * @property int $id
 * @property string $agenda_number
 * @property Carbon $received_on
 * @property string $sender
 * @property string|null $sender_number
 * @property Carbon|null $sender_date
 * @property string $subject
 * @property string|null $scan_path
 * @property string|null $disposition_to
 * @property string|null $disposition_note
 * @property Carbon|null $disposition_done_at
 * @property int $recorded_by
 * @property-read User $recorder
 */
#[Fillable([
    'agenda_number', 'received_on', 'sender', 'sender_number', 'sender_date', 'subject', 'scan_path',
    'disposition_to', 'disposition_note', 'disposition_done_at', 'recorded_by',
])]
class IncomingLetter extends Model
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
            'received_on' => 'date',
            'sender_date' => 'date',
            'disposition_done_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
