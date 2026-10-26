<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A scheduled reminder already sent to a user, so it is not sent twice.
 *
 * @property int $id
 * @property int $user_id
 * @property string $key
 * @property Carbon|null $created_at
 */
#[Fillable(['user_id', 'key', 'created_at'])]
class SentReminder extends Model
{
    public const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }
}
