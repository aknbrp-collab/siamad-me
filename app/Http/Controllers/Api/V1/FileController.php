<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ActsForSignedUser;
use App\Http\Controllers\Controller;
use App\Http\Controllers\PrivateFileController;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Opens a signed download link made by ApiFiles::url() (v6 D37). The person
 * and role in the link are checked again, with the same rules as on the web.
 */
#[Group('Files', weight: 6)]
class FileController extends Controller
{
    use ActsForSignedUser;

    /**
     * Download a file.
     *
     * Only through the signed links in other responses (`…_url`); they expire after a few minutes.
     *
     * @unauthenticated
     */
    public function __invoke(Request $request, string $kind, int $id, PrivateFileController $files): StreamedResponse
    {
        $this->actForSignedUser($request);

        return $files($request, $kind, $id);
    }
}
