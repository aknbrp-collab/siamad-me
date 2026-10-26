<?php

namespace App\Actions\People;

use Illuminate\Http\UploadedFile;
use RuntimeException;

trait StoresProfilePhoto
{
    /**
     * Store an uploaded photo on the private disk and return its path.
     */
    protected function storePhoto(mixed $photo, string $folder): ?string
    {
        if (! $photo instanceof UploadedFile) {
            return null;
        }

        $path = $photo->store("photos/{$folder}", 'local');

        if ($path === false) {
            throw new RuntimeException('The photo could not be stored.');
        }

        return $path;
    }
}
