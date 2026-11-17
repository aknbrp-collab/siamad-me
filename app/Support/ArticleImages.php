<?php

namespace App\Support;

use App\Enums\ArticleStatus;
use App\Models\Article;
use App\Models\ArticleVersion;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The optional picture of an article (v7 D48). Stored on the private disk as a JPEG at most 1600 px wide,
 * under a new name each time (a published version keeps the file it was published with). The CMS loads the
 * published picture from a public link that changes when the picture changes.
 */
final class ArticleImages
{
    public const MAX_WIDTH = 1600;

    public const DIRECTORY = 'articles/images';

    /**
     * Shrink and store an uploaded picture; returns its path.
     */
    public static function store(UploadedFile $file): string
    {
        $image = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));

        if ($image === false) {
            throw ValidationException::withMessages(['image' => __('This file is not an image SIAKAD can read. Use JPG, PNG or WebP.')]);
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1, self::MAX_WIDTH / $width);
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        // On white, so a transparent PNG does not turn black as a JPEG.
        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        ob_start();
        imagejpeg($canvas, null, 85);
        $jpeg = (string) ob_get_clean();

        $path = self::DIRECTORY.'/'.Str::random(32).'.jpg';
        Storage::disk('local')->put($path, $jpeg);

        return $path;
    }

    /**
     * Delete a picture no longer used by the working copy nor by any published version.
     */
    public static function forget(?string $path): void
    {
        if ($path === null) {
            return;
        }

        $used = Article::query()->where('image_path', $path)->exists()
            || ArticleVersion::query()->where('image_path', $path)->exists();

        if (! $used) {
            Storage::disk('local')->delete($path);
        }
    }

    /**
     * The published version's picture, while the article is published (not withdrawn).
     */
    public static function published(Article $article): ?ArticleVersion
    {
        if ($article->published_at === null || $article->status === ArticleStatus::Withdrawn) {
            return null;
        }

        $version = $article->latestVersion();

        return $version?->image_path !== null ? $version : null;
    }

    /**
     * The public link the CMS loads the published picture from (null without one).
     */
    public static function publicUrl(Article $article): ?string
    {
        $version = self::published($article);

        return $version === null || $article->code === null
            ? null
            : route('articles.public-image', ['code' => $article->code, 'hash' => self::hash((string) $version->image_path)]);
    }

    /**
     * Part of the public link: changes with the file, and cannot be guessed from the code.
     */
    public static function hash(string $path): string
    {
        return substr(hash_hmac('sha256', $path, (string) config('app.key')), 0, 24);
    }
}
