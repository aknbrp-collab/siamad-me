<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The institution's identity: text settings and images (app logo,
 * institution logo, name in Arabic calligraphy). Images live on the private
 * disk; logos are served by BrandController, the rest only go into PDFs.
 */
final class Branding
{
    /**
     * Image name => setting key holding its path on the local disk.
     */
    public const IMAGES = [
        'app_logo' => 'branding.app_logo',
        'institution_logo' => 'branding.institution_logo',
        'arabic_name' => 'branding.arabic_name',
    ];

    public static function imagePath(string $image): ?string
    {
        $path = Setting::get(self::IMAGES[$image]);

        return is_string($path) && Storage::disk('local')->exists($path) ? $path : null;
    }

    /**
     * Absolute file path for dompdf, or null when there is no such file.
     */
    public static function file(?string $path): ?string
    {
        return $path !== null && Storage::disk('local')->exists($path) ? Storage::disk('local')->path($path) : null;
    }

    /**
     * A small inline image for previews on Superadmin pages.
     */
    public static function dataUri(?string $path): ?string
    {
        return $path !== null && Storage::disk('local')->exists($path)
            ? 'data:image/png;base64,'.base64_encode((string) Storage::disk('local')->get($path))
            : null;
    }

    /**
     * Public URL of the app logo (null: use the built-in logo). The version
     * changes with every upload, so browsers never show an old one.
     */
    public static function appLogoUrl(): ?string
    {
        $path = self::imagePath('app_logo');

        return $path === null ? null : route('brand.asset', ['asset' => 'app-logo', 'v' => substr(md5($path), 0, 8)]);
    }

    /**
     * Store an uploaded image as a PNG (transparency kept), scaled down to at
     * most $max pixels on its longer side.
     */
    public static function storeImage(UploadedFile $file, string $directory, int $max = 1000): string
    {
        $image = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));

        if ($image === false) {
            throw ValidationException::withMessages(['image' => __('This file is not an image SIAKAD can read. Use PNG or JPG.')]);
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1, $max / max($width, $height));

        if ($scale < 1) {
            $scaled = imagecreatetruecolor(max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));
            imagealphablending($scaled, false);
            imagesavealpha($scaled, true);
            imagefill($scaled, 0, 0, (int) imagecolorallocatealpha($scaled, 0, 0, 0, 127));
            imagecopyresampled($scaled, $image, 0, 0, 0, 0, imagesx($scaled), imagesy($scaled), $width, $height);
            $image = $scaled;
        }

        imagesavealpha($image, true);
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();

        $path = trim($directory, '/').'/'.Str::random(24).'.png';
        Storage::disk('local')->put($path, $png);

        return $path;
    }

    /**
     * Everything the PDF letterhead prints.
     *
     * @return array{name: string, foundation: string|null, address: string|null, contact: string, licence: string|null, logo: string|null, arabic: string|null}
     */
    public static function letterhead(): array
    {
        $contact = collect([
            Setting::get('institution.phone') ? 'Telp. '.Setting::get('institution.phone') : null,
            Setting::get('institution.email'),
            Setting::get('institution.website'),
        ])->filter()->implode(' · ');

        return [
            'name' => (string) Setting::get('institution.name'),
            'foundation' => Setting::get('institution.foundation'),
            'address' => Setting::get('institution.address'),
            'contact' => $contact,
            'licence' => Setting::get('institution.licence'),
            'logo' => self::file(self::imagePath('institution_logo')),
            'arabic' => self::file(self::imagePath('arabic_name')),
        ];
    }
}
