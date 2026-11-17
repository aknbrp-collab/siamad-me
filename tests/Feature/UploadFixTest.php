<?php

use App\Enums\Role;
use App\Models\User;
use App\Support\Upload;
use Illuminate\Foundation\Console\ServeCommand;
use Symfony\Component\Finder\Finder;

/*
 * v7 D42: uploads that never reached SIAKAD.
 */

it('lets artisan serve pass the temporary folder to PHP', function () {
    expect(ServeCommand::$passthroughVariables)->toContain('TEMP', 'TMP', 'TMPDIR')
        ->and(array_unique(ServeCommand::$passthroughVariables))->toHaveCount(count(ServeCommand::$passthroughVariables));
});

it('names no page method after a Livewire built-in', function () {
    // wire:click="upload(…)" runs Livewire's own $wire.upload(name, file), never the page's method:
    // the button did nothing (v7). These are the names Livewire resolves without the "$".
    $builtIns = ['on', 'el', 'id', 'js', 'get', 'set', 'refs', 'call', 'hook', 'watch', 'dirty', 'effect',
        'commit', 'errors', 'island', 'upload', 'entangle', 'dispatch', 'intercept', 'interceptAction',
        'interceptMessage', 'interceptRequest', 'dispatchTo', 'dispatchSelf', 'dispatchEl', 'dispatchRef',
        'removeUpload', 'cancelUpload', 'uploadMultiple'];
    $pattern = '/public function ('.implode('|', $builtIns).')\(/';

    $clashes = [];
    foreach ((new Finder)->files()->in([resource_path('views/pages'), app_path('Livewire')])->name(['*.blade.php', '*.php']) as $file) {
        if (preg_match_all($pattern, $file->getContents(), $matches)) {
            $clashes[] = $file->getRelativePathname().': '.implode(', ', $matches[1]);
        }
    }

    expect($clashes)->toBe([]);
});

it('tells whether a folder can really be written', function () {
    expect(Upload::canWrite(sys_get_temp_dir()))->toBeTrue()
        ->and(Upload::canWrite(sys_get_temp_dir().DIRECTORY_SEPARATOR.'siakad-missing-'.uniqid()))->toBeFalse();
});

it('reads php.ini sizes', function (string $value, int $bytes) {
    expect(Upload::bytes($value))->toBe($bytes);
})->with([
    ['12M', 12 * 1024 * 1024],
    ['512K', 512 * 1024],
    ['1G', 1024 ** 3],
    ['8388608', 8388608],
    ['0', 0],
]);

it('warns the Superadmin when PHP cannot take files as large as the limit', function () {
    $superadmin = User::factory()->withRole(Role::Superadmin)->create();

    $this->actingAs($superadmin)->get(route('superadmin.dashboard'))->assertOk()->assertDontSee('data-test="upload-problems"', false);

    // A limit far above PHP's own upload_max_filesize / post_max_size.
    config(['siakad.uploads.max_kb' => 50 * 1024 * 1024]);

    expect(Upload::problems())->not->toBe([]);
    $this->actingAs($superadmin)->get(route('superadmin.dashboard'))->assertOk()
        ->assertSee('data-test="upload-problems"', false)
        ->assertSee('upload_max_filesize');
});

it('shows the upload warning to the Superadmin only', function () {
    config(['siakad.uploads.max_kb' => 50 * 1024 * 1024]);
    $admin = User::factory()->withRole(Role::Admin)->create();

    $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()->assertDontSee('data-test="upload-problems"', false);
});
