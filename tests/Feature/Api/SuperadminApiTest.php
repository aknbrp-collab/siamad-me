<?php

use App\Enums\Role;
use App\Models\Setting;
use App\Models\Signatory;
use App\Models\Student;
use App\Models\User;
use App\Support\Branding;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->superadmin = User::factory()->withRole(Role::Superadmin)->create();
});

/**
 * Act through a device token in a role (created directly, not through the rate-limited login).
 */
function asSystem(User $user, Role $role = Role::Superadmin): TestCase
{
    app('auth')->forgetGuards();

    return test()->withToken($user->createToken('Test')->plainTextToken)->withHeader('X-Role', $role->value);
}

function pngUpload(int $width = 400, int $height = 200): UploadedFile
{
    $image = imagecreatetruecolor($width, $height);
    ob_start();
    imagepng($image);

    return UploadedFile::fake()->createWithContent('image.png', (string) ob_get_clean());
}

test('only Superadmin reaches /superadmin', function () {
    asSystem(User::factory()->withRole(Role::Admin)->create(), Role::Admin)->getJson('/api/v1/superadmin/users')->assertForbidden();
    asSystem($this->superadmin)->getJson('/api/v1/superadmin/users')->assertOk();
});

test('users: a staff account with a temporary password, roles, and a payment collector', function () {
    $created = asSystem($this->superadmin)->postJson('/api/v1/superadmin/users', ['username' => 'bendahara', 'name' => 'Bendahara', 'roles' => ['admin_payment']])
        ->assertCreated()->json('data');
    expect($created['password'])->toBeString();

    asSystem($this->superadmin)->getJson('/api/v1/superadmin/users?role=admin_payment')->assertOk()->assertJsonPath('data.0.username', 'bendahara');
    asSystem($this->superadmin)->putJson("/api/v1/superadmin/users/{$created['id']}/roles", ['roles' => ['admin_payment', 'academic_monitor']])->assertOk()
        ->assertJsonPath('data.roles', ['admin_payment', 'academic_monitor']);
    asSystem($this->superadmin)->putJson("/api/v1/superadmin/users/{$created['id']}", ['username' => 'bendahara', 'name' => 'Ibu Bendahara'])->assertOk()->assertJsonPath('data.name', 'Ibu Bendahara');

    $student = Student::factory()->female()->create();
    asSystem($this->superadmin)->getJson("/api/v1/superadmin/users/{$student->user_id}")->assertOk()
        ->assertJsonPath('data.profile.kind', 'student')->assertJsonPath('data.can_hold_staff_roles', false);
    asSystem($this->superadmin)->putJson("/api/v1/superadmin/users/{$student->user_id}/collector", ['scope' => 'female'])->assertOk()
        ->assertJsonPath('data.collector_scope', 'female')->assertJsonPath('data.roles', ['student', 'payment_collector']);
});

test('settings, identity and appearance, with brand images', function () {
    $settings = asSystem($this->superadmin)->getJson('/api/v1/superadmin/settings')->assertOk()->json('data');
    asSystem($this->superadmin)->putJson('/api/v1/superadmin/settings', [...$settings, 'attendance_min_percent' => 120])->assertUnprocessable()->assertJsonValidationErrors('attendance_min_percent');
    asSystem($this->superadmin)->putJson('/api/v1/superadmin/settings', [...$settings, 'attendance_min_percent' => 80, 'bank_accounts' => 'BSI 7123 a.n. Yayasan'])->assertOk();
    expect(Setting::get('attendance.min_percent'))->toBe(80);

    asSystem($this->superadmin)->putJson('/api/v1/superadmin/identity', ['name' => "Ma'had Aly Contoh", 'city' => 'Situbondo', 'email' => 'bukan-email'])->assertUnprocessable();
    asSystem($this->superadmin)->putJson('/api/v1/superadmin/identity', ['name' => "Ma'had Aly Contoh", 'city' => 'Situbondo'])->assertOk()->assertJsonPath('data.city', 'Situbondo');
    asSystem($this->superadmin)->putJson('/api/v1/superadmin/appearance', ['app_name' => 'SIAKAD Contoh', 'login_title' => 'Ahlan'])->assertOk()->assertJsonPath('data.login_title', 'Ahlan');
    expect(Branding::appName())->toBe('SIAKAD Contoh');

    asSystem($this->superadmin)->post('/api/v1/superadmin/images', ['image' => 'app_logo', 'file' => pngUpload()], ['Accept' => 'application/json'])->assertNoContent();
    asSystem($this->superadmin)->getJson('/api/v1/superadmin/appearance')->assertJsonPath('data.images.app_logo', fn ($preview) => str_starts_with((string) $preview, 'data:image/png'));
    asSystem($this->superadmin)->deleteJson('/api/v1/superadmin/images/app_logo')->assertNoContent();
    expect(Branding::imagePath('app_logo'))->toBeNull();
});

test('signatories, stamps, print defaults and letter types', function () {
    $signatory = asSystem($this->superadmin)->post('/api/v1/superadmin/signatories', ['name' => 'KH. Ahmad', 'position' => 'Mudir', 'signature' => pngUpload(800, 300)], ['Accept' => 'application/json'])
        ->assertOk()->json('data.id');
    asSystem($this->superadmin)->postJson('/api/v1/superadmin/stamps', ['name' => 'Stempel'])->assertUnprocessable()->assertJsonValidationErrors('image');
    $stamp = asSystem($this->superadmin)->post('/api/v1/superadmin/stamps', ['name' => 'Stempel', 'image' => pngUpload()], ['Accept' => 'application/json'])->assertOk()->json('data.id');

    asSystem($this->superadmin)->putJson('/api/v1/superadmin/print-defaults', ['defaults' => ['transcript' => ['signatory' => $signatory, 'stamp' => $stamp, 'images' => true]]])->assertNoContent();
    asSystem($this->superadmin)->getJson('/api/v1/superadmin/signatures')->assertOk()
        ->assertJsonPath('data.defaults.transcript.signatory', $signatory)
        ->assertJsonPath('data.signatories.0.signature_preview', fn ($preview) => str_starts_with((string) $preview, 'data:image/png'));
    expect(Signatory::query()->findOrFail($signatory)->signature_path)->not->toBeNull();

    $type = asSystem($this->superadmin)->postJson('/api/v1/superadmin/letter-types', ['name' => 'Surat Perintah Tugas', 'code' => 'spt', 'subject' => 'teacher', 'template' => 'Menugaskan {muhadhir.nama}.'])
        ->assertOk()->json('data.id');
    asSystem($this->superadmin)->getJson('/api/v1/superadmin/letter-types')->assertOk()->assertJsonFragment(['id' => $type, 'code' => 'SPT', 'subject' => 'teacher']);
});

test('templates download through signed links; the audit log shows changes', function () {
    $templates = asSystem($this->superadmin)->getJson('/api/v1/superadmin/templates')->assertOk()->json('data');
    expect($templates)->not->toBeEmpty();

    app('auth')->forgetGuards();
    $this->get($templates[0]['download_url'])->assertOk();

    asSystem($this->superadmin)->putJson('/api/v1/superadmin/identity', ['name' => 'Ma\'had Aly Audit'])->assertOk();
    asSystem($this->superadmin)->getJson('/api/v1/superadmin/audit-log?search=setting')->assertOk()
        ->assertJsonPath('data.0.record', 'Setting')->assertJsonPath('data.0.user', $this->superadmin->name);
});
