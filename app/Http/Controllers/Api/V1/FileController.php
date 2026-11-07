<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Controllers\PrivateFileController;
use App\Models\User;
use App\Support\ActiveRole;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Opens a signed download link made by ApiFiles (v6 D37). The person and role
 * in the link are checked again, with the same rules as on the web.
 *
 * @tags Files
 */
class FileController extends Controller
{
    /**
     * Download a file.
     *
     * Only through the signed links in other responses (`…_url`); they expire after a few minutes.
     *
     * @unauthenticated
     */
    public function __invoke(Request $request, string $kind, int $id, PrivateFileController $files): StreamedResponse
    {
        $user = User::query()->whereKey($request->integer('user'))->where('is_active', true)->firstOrFail();

        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);

        $role = Role::tryFrom((string) $request->query('role'));

        if ($role !== null && $user->hasRole($role)) {
            $request->attributes->set(ActiveRole::REQUEST_KEY, $role);
        }

        return $files($request, $kind, $id);
    }
}
