<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * An income or expense category of the cash book (e.g. Honorarium, Infaq).
 *
 * @property int $id
 * @property string $kind income | expense
 * @property string $name
 * @property bool $is_active
 */
#[Fillable(['kind', 'name', 'is_active'])]
class CashCategory extends Model
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
