<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $letter
 * @property string $min_score
 * @property string $point
 */
#[Fillable(['letter', 'min_score', 'point'])]
class GradeScale extends Model
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
            'min_score' => 'decimal:2',
            'point' => 'decimal:2',
        ];
    }

    /**
     * The scale from the highest band down.
     *
     * @return Collection<int, self>
     */
    public static function ordered(): Collection
    {
        return self::query()->orderByDesc('min_score')->get();
    }

    /**
     * Letter and point for a final score (0–100).
     *
     * @param  Collection<int, self>|null  $scale  Pass a loaded scale when resolving many scores.
     * @return array{letter: string, point: string}
     */
    public static function resolve(float $score, ?Collection $scale = null): array
    {
        $band = ($scale ?? self::ordered())->first(fn (self $band) => $score >= (float) $band->min_score);

        return $band === null
            ? ['letter' => 'E', 'point' => '0.00']
            : ['letter' => $band->letter, 'point' => $band->point];
    }
}
