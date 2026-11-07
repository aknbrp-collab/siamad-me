<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Support\Branding;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

/**
 * What the app shows before and after login: its name, logo and login page
 * (Tampilan aplikasi), and the institution (Identitas).
 */
#[Group('App', weight: 1)]
class AppInfoController extends Controller
{
    /**
     * App appearance and institution.
     *
     * Public, so the login screen can use it. Image URLs are public and change when the image changes.
     *
     * @unauthenticated
     */
    public function __invoke(): JsonResponse
    {
        $version = substr(md5((string) Branding::iconPath()), 0, 8);

        return response()->json(['data' => [
            'api_version' => 'v1',
            'app' => [
                'name' => Branding::appName(),
                'short_name' => Branding::appShortName(),
                'logo_url' => Branding::appLogoUrl(),
                'icon_url' => route('brand.asset', ['asset' => 'icon-512', 'v' => $version]),
                'login_title' => Branding::loginTitle(),
                'login_text' => Branding::loginText(),
                'login_background_url' => Branding::loginBackgroundUrl(),
                'theme_color' => '#66BB6A',
            ],
            'institution' => [
                'name' => (string) Setting::get('institution.name'),
                'short_name' => Setting::get('institution.short_name'),
                'address' => Setting::get('institution.address'),
                'city' => Setting::get('institution.city'),
                'phone' => Setting::get('institution.phone'),
                'email' => Setting::get('institution.email'),
                'website' => Setting::get('institution.website'),
            ],
            /** Whether the server sends push messages through Firebase yet (v6 D39). */
            'push_enabled' => false,
        ]]);
    }
}
