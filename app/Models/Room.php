<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Enums\Gender;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An exam room: capacity and whether it seats putra or putri.
 *
 * @property int $id
 * @property string $name
 * @property int $capacity
 * @property Gender $gender
 * @property bool $is_active
 * @property Carbon|null $created_at
 */
#[Fillable(['name', 'capacity', 'gender', 'is_active'])]
class Room extends Model
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
            'capacity' => 'integer',
            'gender' => Gender::class,
            'is_active' => 'boolean',
        ];
    }

    public function label(): string
    {
        return $this->name.' · '.$this->gender->classLabel().' · '.trans_choice(':count seat|:count seats', $this->capacity);
    }
}
