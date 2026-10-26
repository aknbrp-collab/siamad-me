<?php

use App\Enums\Role;
use App\Models\User;

/**
 * Every literal __('…') / trans_choice('…') / #[Title('…')] key in app, views and routes.
 *
 * @return list<string>
 */
function translationKeys(): array
{
    $pattern = '/(?:\b__|@lang|\btrans_choice|\btrans|#\[Title|\$this->text)\(\s*(\'(?:\\\\.|[^\'\\\\])*\'|"(?:\\\\.|[^"\\\\])*")\s*[,)]/s';
    $keys = [];

    foreach (['app', 'resources/views', 'routes'] as $dir) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($dir), FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if (! str_ends_with($file->getFilename(), '.php')) {
                continue;
            }

            preg_match_all($pattern, (string) file_get_contents($file->getPathname()), $matches);

            foreach ($matches[1] as $literal) {
                $body = substr($literal, 1, -1);
                $key = $literal[0] === "'" ? strtr($body, ['\\\\' => '\\', "\\'" => "'"]) : stripcslashes($body);

                if ($key !== '' && ! str_contains($key, '$')) {
                    $keys[$key] = true;
                }
            }
        }
    }

    return array_keys($keys);
}

test('every UI string has an Indonesian translation', function () {
    $translations = json_decode((string) file_get_contents(lang_path('id.json')), true);

    $missing = array_values(array_diff(translationKeys(), array_keys($translations)));

    expect($missing)->toBe([]);
});

test('translations keep their placeholders', function () {
    $translations = json_decode((string) file_get_contents(lang_path('id.json')), true);

    foreach ($translations as $key => $value) {
        // Sets, not lists: a plural key ("…|…") repeats its placeholders; Indonesian needs one form.
        preg_match_all('/:[a-z_]+/', $key, $expected);
        preg_match_all('/:[a-z_]+/', $value, $actual);
        $expected = array_values(array_unique($expected[0]));
        $actual = array_values(array_unique($actual[0]));
        sort($expected);
        sort($actual);

        expect($actual)->toBe($expected, "Placeholders differ in: {$key}");
    }
});

test('the portal renders in Indonesian', function () {
    app()->setLocale('id');

    $this->get(route('login'))->assertOk()->assertSee('Masuk ke SIAKAD');

    $this->actingAs(User::factory()->withRole(Role::Superadmin)->create())
        ->get(route('superadmin.users.index'))
        ->assertOk()
        ->assertSee('<title>', false)
        ->assertSee('Pengguna &amp; peran - ', false)
        ->assertSee('Tambah akun staf');
});

test('validation messages are in Indonesian', function () {
    app()->setLocale('id');

    expect(validator(['name' => ''], ['name' => 'required'])->errors()->first('name'))
        ->toBe('Kolom nama wajib diisi.');
});

test('ustadz are called muhadhir throughout the Indonesian interface', function () {
    $translations = json_decode((string) file_get_contents(lang_path('id.json')), true);

    $stillUstadz = array_filter($translations, fn (string $value) => stripos($value, 'ustadz') !== false);

    app()->setLocale('id');

    expect($stillUstadz)->toBe([])
        ->and(Role::Teacher->label())->toBe('Muhadhir');
});

test('the muhadhir area lives at /muhadhir and old /ustadz links redirect', function () {
    expect(route('ustadz.dashboard', absolute: false))->toBe('/muhadhir');

    $this->get('/ustadz')->assertRedirect('/muhadhir')->assertStatus(301);
    $this->get('/ustadz/offerings/5/grades')->assertRedirect('/muhadhir/offerings/5/grades');

    $this->actingAs(User::factory()->withRole(Role::Teacher)->create())
        ->get('/muhadhir')
        ->assertOk();
});
