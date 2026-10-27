<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Someone who signs printed documents, with an optional signature image.
 *
 * @property int $id
 * @property string $name
 * @property string $position
 * @property string|null $office
 * @property string|null $signature_path
 * @property bool $is_active
 * @property Carbon|null $created_at
 */
#[Fillable(['name', 'position', 'office', 'signature_path', 'is_active'])]
class Signatory extends Model
{
    use Auditable;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
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
            'is_active' => 'boolean',
        ];
    }

    public function label(): string
    {
        return $this->name.' — '.$this->position.($this->office ? ' ('.$this->office.')' : '');
    }
}
