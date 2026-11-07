<?php

use App\Enums\Role;
use App\Models\Setting;
use App\Models\User;
use App\Support\Branding;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->superadmin = User::factory()->withRole(Role::Superadmin)->create();
});

function squarePng(int $size, array $rgb): UploadedFile
{
    $image = imagecreatetruecolor($size, $size);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, ...$rgb));
    ob_start();
    imagepng($image);

    return UploadedFile::fake()->createWithContent('image.png', (string) ob_get_clean());
}

test('without settings the app uses APP_NAME, the institution short name and the default login texts', function () {
    Setting::set('institution.short_name', 'MA Contoh');

    expect(Branding::appName())->toBe(config('siakad.app_name'))
        ->and(Branding::appShortName())->toBe('MA Contoh')
        ->and(Branding::loginTitle())->toBe(__('Log in to SIAKAD'));

    $this->get('/login')->assertOk()->assertSee(__('Log in to SIAKAD'))->assertDontSeeHtml('data-test="login-background"');
});

test('superadmin sets the app name, short name and login texts; they show everywhere but on documents', function () {
    $this->actingAs($this->superadmin)->get(route('superadmin.appearance'))->assertOk()->assertSeeHtml('data-test="login-preview"');

    Livewire::test('pages::superadmin.appearance')
        ->set('appName', 'SIAKAD Ma\'had Aly Al-Contoh')
        ->set('shortName', 'SIAKAD MAC')
        ->set('loginTitle', 'Ahlan wa sahlan')
        ->set('loginText', 'Masuk dengan NIM, NIDM, atau kode wali.')
        ->call('save')
        ->assertHasNoErrors()
        ->set('shortName', str_repeat('x', 21))
        ->call('save')
        ->assertHasErrors('shortName');

    expect(Setting::get('app.short_name'))->toBe('SIAKAD MAC')
        ->and(Setting::get('institution.name'))->not->toBe('SIAKAD Ma\'had Aly Al-Contoh');

    // The sidebar uses the short name.
    $this->get(route('superadmin.users.index'))->assertSee('SIAKAD MAC');

    // The installed app.
    $this->get('/manifest.webmanifest')
        ->assertJsonPath('name', 'SIAKAD Ma\'had Aly Al-Contoh')
        ->assertJsonPath('short_name', 'SIAKAD MAC');

    // Login page: name, title and text; the browser tab and emails (applied on every boot).
    auth()->logout();
    Branding::applyToConfig();
    $this->get('/login')->assertOk()
        ->assertSee('SIAKAD Ma\'had Aly Al-Contoh')
        ->assertSee('Ahlan wa sahlan')
        ->assertSee('Masuk dengan NIM, NIDM, atau kode wali.')
        ->assertSee(__('Log in').' - SIAKAD Ma\'had Aly Al-Contoh');
    expect(config('app.name'))->toBe('SIAKAD Ma\'had Aly Al-Contoh')
        ->and(config('mail.from.name'))->toBe('SIAKAD Ma\'had Aly Al-Contoh');

    // Clearing a field goes back to the default.
    $this->actingAs($this->superadmin);
    Livewire::test('pages::superadmin.appearance')->set('loginTitle', '  ')->call('save');
    expect(Setting::get('app.login_title'))->toBeNull()->and(Branding::loginTitle())->toBe(__('Log in to SIAKAD'));
});

test('the app icon is used for icons instead of the logo, and the login background is public', function () {
    $this->actingAs($this->superadmin);

    Livewire::test('pages::superadmin.appearance')
        ->set('uploads.app_logo', squarePng(600, [46, 125, 50]))
        ->set('uploads.app_icon', squarePng(600, [200, 30, 30]))
        ->set('uploads.login_background', squarePng(2400, [10, 10, 120]))
        ->assertHasNoErrors();

    expect(Branding::iconPath())->toBe(Branding::imagePath('app_icon'));
    [$width] = getimagesizefromstring((string) Storage::disk('local')->get(Branding::imagePath('login_background')));
    expect($width)->toBe(2000);

    auth()->logout();
    $icon = $this->get(route('brand.asset', 'icon-192'))->assertOk();
    $png = imagecreatefromstring((string) file_get_contents($icon->baseResponse->getFile()->getPathname()));
    // The centre of the icon is the red app icon, not the green logo.
    expect(imagecolorsforindex($png, imagecolorat($png, 96, 96)))->toMatchArray(['red' => 200, 'green' => 30, 'blue' => 30]);

    $this->get(route('brand.asset', 'login-background'))->assertOk()->assertHeader('content-type', 'image/png');
    $this->get('/login')->assertSeeHtml('data-test="login-background"');

    // Removing the icon: icons come from the logo again.
    $this->actingAs($this->superadmin);
    Livewire::test('pages::superadmin.appearance')->call('removeImage', 'app_icon');
    expect(Branding::iconPath())->toBe(Branding::imagePath('app_logo'));

    // Identity does not manage the app images, and the appearance page not the institution's.
    Livewire::test('pages::superadmin.identity')->call('removeImage', 'app_logo')->assertNotFound();
    Livewire::test('pages::superadmin.appearance')->call('removeImage', 'institution_logo')->assertNotFound();
});

test('only superadmin opens the appearance page', function () {
    $this->actingAs(User::factory()->withRole(Role::Admin)->create())->withSession(['active_role' => Role::Admin->value])
        ->get(route('superadmin.appearance'))->assertForbidden();
});
