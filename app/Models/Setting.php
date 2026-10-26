<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Key/value application settings. Defaults live in config('siakad.settings');
 * a row only exists once a value has been changed.
 *
 * @property string $key
 * @property mixed $value
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['key', 'value'])]
class Setting extends Model
{
    use Auditable;

    private const CACHE_KEY = 'settings';

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $stored = Cache::rememberForever(self::CACHE_KEY, fn () => self::query()->pluck('value', 'key')->all());

        if (array_key_exists($key, $stored)) {
            return $stored[$key];
        }

        // Keys contain dots, so read the defaults array directly rather than
        // through config()'s dot notation.
        return config('siakad.settings', [])[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        self::query()->updateOrCreate(['key' => $key], ['value' => $value]);

        Cache::forget(self::CACHE_KEY);
    }
}
