<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One call between SIAKAD and the CMS (v5 D31), for support: when, which
 * endpoint, about what, the HTTP status, how long it took and a short piece
 * of the response.
 *
 * @property int $id
 * @property string $direction out | in
 * @property string $method
 * @property string $endpoint
 * @property string|null $subject
 * @property int|null $status
 * @property int|null $duration_ms
 * @property string|null $request_id
 * @property string|null $response
 * @property string|null $error
 * @property Carbon $created_at
 */
#[Fillable(['direction', 'method', 'endpoint', 'subject', 'status', 'duration_ms', 'request_id', 'response', 'error', 'created_at'])]
class CmsRequest extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['status' => 'integer', 'duration_ms' => 'integer'];
    }

    public function succeeded(): bool
    {
        return $this->status !== null && $this->status >= 200 && $this->status < 300;
    }
}
