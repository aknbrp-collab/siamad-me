<?php

namespace App\Support;

use App\Enums\Role;
use App\Http\Controllers\Api\V1\PdfController;
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
     * A PDF made on request (KHS, transcript, exam card, letter, receipt), see PdfController::DOCUMENTS.
     *
     * @param  array<string, int>  $ids  e.g. ['student' => 5, 'semester' => 2]
     */
    public static function pdf(User $user, ?Role $role, string $document, array $ids): string
    {
        abort_unless(array_key_exists($document, PdfController::DOCUMENTS), 404);

        return self::sign('api.v1.pdf', ['document' => $document, ...$ids], $user, $role);
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
