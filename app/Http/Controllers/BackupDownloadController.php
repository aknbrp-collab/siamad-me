<?php

namespace App\Http\Controllers;

use App\Support\Backups;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Download a backup as one .zip (D50): Superadmin only (the route's role middleware).
 */
class BackupDownloadController extends Controller
{
    public function __invoke(string $backup): BinaryFileResponse
    {
        $path = Backups::archive($backup);
        Backups::audit('backup.downloaded', $backup);

        return response()->download($path, "siakad-backup-{$backup}.zip")->deleteFileAfterSend();
    }
}
