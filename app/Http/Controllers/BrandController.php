<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Support\Branding;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public brand assets: the app logo and the app icons (sidebar, login page,
 * browser tab, installed app). Icons are made from the uploaded app logo,
 * or fall back to the built-in ones in public/icons. Signatures and stamps
 * are never served here.
 */
class BrandController extends Controller
{
    /**
     * Square icon sizes and their built-in fallbacks.
     *
     * @var array<string, array{int<1, max>, string}>
     */
    private const ICONS = [
        'icon-192' => [192, 'icons/icon-192.png'],
        'icon-512' => [512, 'icons/icon-512.png'],
        'apple-touch-icon' => [180, 'apple-touch-icon.png'],
    ];

    public function asset(string $asset): Response
    {
        $logo = Branding::imagePath('app_logo');

        if ($asset === 'app-logo') {
            abort_if($logo === null, 404);

            return $this->png(Storage::disk('local')->path($logo));
        }

        [$size, $fallback] = self::ICONS[$asset];

        if ($logo === null) {
            return $this->png(public_path($fallback));
        }

        // Made once per uploaded logo and size.
        $icon = 'branding/icons/'.md5($logo).'-'.$size.'.png';

        if (! Storage::disk('local')->exists($icon)) {
            $png = $this->squareIcon(Storage::disk('local')->path($logo), $size);

            // An unreadable logo file: keep the built-in icon rather than fail.
            if ($png === null) {
                return $this->png(public_path($fallback));
            }

            Storage::disk('local')->put($icon, $png);
        }

        return $this->png(Storage::disk('local')->path($icon));
    }

    public function manifest(): JsonResponse
    {
        $version = substr(md5((string) Branding::imagePath('app_logo')), 0, 8);
        $icon = fn (string $asset, string $size, string $purpose) => [
            'src' => route('brand.asset', ['asset' => $asset, 'v' => $version], false),
            'sizes' => $size,
            'type' => 'image/png',
            'purpose' => $purpose,
        ];

        return response()->json([
            'name' => (string) Setting::get('institution.name'),
            'short_name' => (string) (Setting::get('institution.short_name') ?: 'SIAKAD'),
            'description' => 'Sistem informasi akademik',
            'lang' => 'id',
            'start_url' => '/dashboard',
            'scope' => '/',
            'display' => 'standalone',
            'background_color' => '#ffffff',
            'theme_color' => '#66BB6A',
            'icons' => [
                $icon('icon-192', '192x192', 'any'),
                $icon('icon-512', '512x512', 'any'),
                $icon('icon-512', '512x512', 'maskable'),
            ],
        ], 200, ['Content-Type' => 'application/manifest+json']);
    }

    private function png(string $path): BinaryFileResponse
    {
        return response()->file($path, ['Content-Type' => 'image/png', 'Cache-Control' => 'public, max-age=604800']);
    }

    /**
     * The logo centred on a white square with a margin, so it also survives
     * the round or squircle masks of phone home screens.
     *
     * @param  int<1, max>  $size
     */
    private function squareIcon(string $logoPath, int $size): ?string
    {
        $logo = @imagecreatefrompng($logoPath);

        if ($logo === false) {
            return null;
        }

        $canvas = imagecreatetruecolor($size, $size);
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        imagealphablending($canvas, true);

        $box = (int) round($size * 0.68);
        $scale = min($box / imagesx($logo), $box / imagesy($logo));
        $width = (int) round(imagesx($logo) * $scale);
        $height = (int) round(imagesy($logo) * $scale);

        imagecopyresampled($canvas, $logo, intdiv($size - $width, 2), intdiv($size - $height, 2), 0, 0, $width, $height, imagesx($logo), imagesy($logo));

        ob_start();
        imagepng($canvas);

        return (string) ob_get_clean();
    }
}
