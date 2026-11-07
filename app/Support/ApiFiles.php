<?php

namespace App\Support;

use App\Enums\Role;
use App\Http\Controllers\Api\V1\DownloadController;
use App\Http\Controllers\PrivateFileController;
use App\Models\User;
use Illuminate\Support\Facades\URL;

/**
 * Download links for the mobile app (v6 D37): signed URLs that expire after a
 * few minutes and carry who asked and in which role. The access rules of the
 * web controllers run again when the link is opened, so the app never
 * handles private paths or sends its token to a browser.
 */
final class ApiFiles
{
    public const MINUTES = 10;

    /**
     * A private file (photo, document, material, submission…), see PrivateFileController::KINDS.
     */
    public static function url(User $user, ?Role $role, string $kind, int $id): string
    {
        abort_unless(in_array($kind, PrivateFileController::KINDS, true), 404);

        return self::sign('api.v1.files', ['kind' => $kind, 'id' => $id], $user, $role);
    }

    /**
     * A file made on request (KHS, transcript, exam card, letter, receipt, muhadhir templates and journal),
     * see DownloadController::DOCUMENTS.
     *
     * @param  array<string, int|string>  $parameters  e.g. ['student' => 5, 'semester' => 2]
     */
    public static function download(User $user, ?Role $role, string $document, array $parameters): string
    {
        abort_unless(array_key_exists($document, DownloadController::DOCUMENTS), 404);

        return self::sign('api.v1.downloads', ['document' => $document, ...$parameters], $user, $role);
    }

    /**
     * @param  array<string, int|string>  $parameters
     */
    private static function sign(string $route, array $parameters, User $user, ?Role $role): string
    {
        return URL::temporarySignedRoute($route, now()->addMinutes(self::MINUTES), array_filter([
            ...$parameters,
            'user' => $user->id,
            'role' => $role?->value,
        ], fn ($value) => $value !== null));
    }
}
