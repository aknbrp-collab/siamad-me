<?php

namespace App\Support;

use App\Enums\Role;
use App\Http\Controllers\PrivateFileController;
use App\Models\User;
use Illuminate\Support\Facades\URL;

/**
 * Download links for the mobile app (v6 D37): signed URLs that expire after a
 * few minutes and carry who asked and in which role. The access rules of
 * PrivateFileController run again when the link is opened, so the app never
 * handles private paths or sends its token to a browser.
 */
final class ApiFiles
{
    public const MINUTES = 10;

    public static function url(User $user, ?Role $role, string $kind, int $id): string
    {
        abort_unless(in_array($kind, PrivateFileController::KINDS, true), 404);

        return URL::temporarySignedRoute('api.v1.files', now()->addMinutes(self::MINUTES), array_filter([
            'kind' => $kind,
            'id' => $id,
            'user' => $user->id,
            'role' => $role?->value,
        ], fn ($value) => $value !== null));
    }
}
