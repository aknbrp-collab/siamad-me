<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A stamp image printed over a signature (e.g. the institution's stamp, the
 * finance office's stamp).
 *
 * @property int $id
 * @property string $name
 * @property string $image_path
 * @property bool $is_active
 * @property Carbon|null $created_at
 */
#[Fillable(['name', 'image_path', 'is_active'])]
class Stamp extends Model
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
}
