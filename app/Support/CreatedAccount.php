<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * A newly created person and the one-time temporary password of their login.
 * The password is never stored in plain text; show or print it once.
 */
final readonly class CreatedAccount
{
    public function __construct(
        public Model $model,
        public string $username,
        public string $name,
        public string $password,
    ) {}
}
