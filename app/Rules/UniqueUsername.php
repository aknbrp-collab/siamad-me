<?php

namespace App\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;

/**
 * NIM, NIDM, guardian codes and staff IDs share one login namespace
 * (users.username, stored lowercased).
 */
class UniqueUsername implements ValidationRule
{
    public function __construct(private readonly ?int $ignoreUserId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $taken = User::query()
            ->where('username', Str::lower(trim((string) $value)))
            ->when($this->ignoreUserId, fn ($query) => $query->whereKeyNot($this->ignoreUserId))
            ->exists();

        if ($taken) {
            $fail(__('This ID number is already used by another account.'));
        }
    }
}
