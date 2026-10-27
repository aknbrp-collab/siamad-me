<?php

use App\Actions\Enrollment\ManageClassEnrollments;
use App\Enums\PrintDocument;
use App\Enums\Role;
use App\Models\ClassGroup;
use App\Models\Semester;
use App\Models\Setting;
use App\Models\Signatory;
use App\Models\Stamp;
use App\Models\Student;
use App\Models\User;
use App\Support\Branding;
use App\Support\PrintMarks;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->superadmin = User::factory()->withRole(Role::Superadmin)->create();
});

/**
 * A real PNG with a transparent background, as people upload logos.
 */
function transparentPng(int $width = 1600, int $height = 400): UploadedFile
{
    $image = imagecreatetruecolor($width, $height);
    imagesavealpha($image, true);
    imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
    imagefilledrectangle($image, 10, 10, 60, 60, (int) imagecolorallocate($image, 46, 125, 50));
    ob_start();
    imagepng($image);

    return UploadedFile::fake()->createWithContent('logo.png', (string) ob_get_clean());
}

test('superadmin keeps the identity of the institution', function () {
    $this->actingAs($this->superadmin)->get(route('superadmin.identity'))->assertOk();

    Livewire::test('pages::superadmin.identity')
        ->set('institutionName', "Ma'had Aly Contoh")
        ->set('shortName', 'MA Contoh')
        ->set('city', 'Situbondo')
        ->set('licence', 'SK Dirjen Pendis No. 1234 Tahun 2024')
        ->set('email', 'bukan-email')
        ->call('save')
        ->assertHasErrors('email')
        ->set('email', 'akademik@contoh.ac.id')
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::get('institution.name'))->toBe("Ma'had Aly Contoh")
        ->and(Setting::get('institution.city'))->toBe('Situbondo')
        ->and(Setting::get('institution.email'))->toBe('akademik@contoh.ac.id')
        ->and(Setting::query()->find('institution.website'))->toBeNull();
});

test('uploaded logos are stored as scaled PNGs and served as the app logo and icons', function () {
    $this->actingAs($this->superadmin)->get(route('superadmin.identity'));

    Livewire::test('pages::superadmin.identity')
        ->set('uploads.app_logo', transparentPng())
        ->assertHasNoErrors()
        ->set('uploads.arabic_name', UploadedFile::fake()->create('nama.pdf', 10, 'application/pdf'))
        ->assertHasErrors('uploads.arabic_name');

    $path = Branding::imagePath('app_logo');
    [$width, $height] = getimagesizefromstring((string) Storage::disk('local')->get($path));
    expect([$width, $height])->toBe([1000, 250]);

    // Public: the login page needs them before anyone signs in.
    auth()->logout();
    $this->get(route('brand.asset', 'app-logo'))->assertOk()->assertHeader('content-type', 'image/png');
    $icon = $this->get(route('brand.asset', 'icon-192'))->assertOk();
    expect(getimagesizefromstring((string) file_get_contents($icon->baseResponse->getFile()->getPathname()))[0])->toBe(192);
    $this->get('/login')->assertSee(Branding::appLogoUrl(), false);
    $this->get('/manifest.webmanifest')->assertOk()->assertJsonPath('icons.0.sizes', '192x192');
});

test('without an uploaded logo the built-in mark and icons are used', function () {
    $this->get(route('brand.asset', 'app-logo'))->assertNotFound();
    $this->get(route('brand.asset', 'icon-512'))->assertOk();
    expect(Branding::appLogoUrl())->toBeNull();
});

test('signatures and stamps are kept as lists with a default per document', function () {
    $this->actingAs($this->superadmin)->get(route('superadmin.signatures'))->assertOk();

    Livewire::test('pages::superadmin.signatures')
        ->call('createSignatory')
        ->set('name', 'KH. Ahmad Fauzi')
        ->set('position', 'Mudir')
        ->set('signature', transparentPng(800, 300))
        ->call('saveSignatory')
        ->assertHasNoErrors()
        ->call('createStamp')
        ->set('stampName', 'Stempel Mahad Aly')
        ->call('saveStamp')
        ->assertHasErrors('stampImage')
        ->set('stampImage', transparentPng(400, 400))
        ->call('saveStamp')
        ->assertHasNoErrors();

    $mudir = Signatory::query()->sole();
    $stamp = Stamp::query()->sole();
    expect(getimagesizefromstring((string) Storage::disk('local')->get($mudir->signature_path))[0])->toBe(600);

    Livewire::test('pages::superadmin.signatures')
        ->set('defaults.transcript.signatory', (string) $mudir->id)
        ->set('defaults.transcript.stamp', (string) $stamp->id)
        ->set('defaults.receipt.images', false)
        ->call('saveDefaults');

    $marks = PrintMarks::defaults(PrintDocument::Transcript);
    expect($marks->signatory?->is($mudir))->toBeTrue()
        ->and($marks->stamp?->is($stamp))->toBeTrue()
        ->and($marks->signatureFile())->toEndWith('.png')
        ->and(PrintMarks::defaults(PrintDocument::Receipt)->images)->toBeFalse()
        ->and(PrintMarks::defaults(PrintDocument::Khs)->signatory)->toBeNull();
});

test('staff may choose another signatory or print without images; mahasantri always get the default', function () {
    $mudir = Signatory::create(['name' => 'KH. Ahmad Fauzi', 'position' => 'Mudir']);
    $bendahara = Signatory::create(['name' => 'Siti Aminah', 'position' => 'Bendahara']);
    $retired = Signatory::create(['name' => 'Lama', 'position' => 'Mudir', 'is_active' => false]);
    Setting::set('print.transcript.signatory', $mudir->id);

    $choice = Request::create('/x', 'GET', ['signatory' => $bendahara->id, 'stamp' => 0, 'marks' => 0]);

    $this->actingAs(User::factory()->withRole(Role::Admin)->create());
    session(['active_role' => Role::Admin->value]);
    $staff = PrintMarks::for(PrintDocument::Transcript, $choice);
    expect($staff->signatory?->is($bendahara))->toBeTrue()
        ->and($staff->images)->toBeFalse()
        ->and($staff->signatureFile())->toBeNull();

    expect(PrintMarks::for(PrintDocument::Transcript, Request::create('/x', 'GET', ['signatory' => $retired->id]))->signatory)->toBeNull();

    $this->actingAs(Student::factory()->create()->user);
    session(['active_role' => Role::Student->value]);
    $student = PrintMarks::for(PrintDocument::Transcript, $choice);
    expect($student->signatory?->is($mudir))->toBeTrue()
        ->and($student->images)->toBeTrue();
});

test('PDFs print the letterhead and the chosen signatory', function () {
    Setting::set('institution.city', 'Situbondo');
    $mudir = Signatory::create(['name' => 'KH. Ahmad Fauzi', 'position' => 'Mudir']);
    $semester = Semester::factory()->active()->create();
    $student = Student::factory()->create();
    app(ManageClassEnrollments::class)->enroll($semester, ClassGroup::factory()->create(), [$student->id]);

    $this->actingAs(User::factory()->withRole(Role::Admin)->create())->withSession(['active_role' => Role::Admin->value]);

    $this->get(route('records.transcript', [$student, 'signatory' => $mudir->id, 'marks' => 1]))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
    $this->get(route('records.khs', [$student, $semester]))->assertOk();

    $this->actingAs($this->superadmin)->withSession(['active_role' => Role::Superadmin->value])
        ->get(route('superadmin.identity.preview', ['document' => 'receipt']))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    // The signature block itself, rendered as HTML.
    $html = view('records.preview', ['document' => PrintDocument::Transcript, 'marks' => new PrintMarks($mudir, null, true)])->render();
    expect($html)->toContain('Situbondo, ')->toContain('KH. Ahmad Fauzi')->toContain('Mudir');
});

test('staff get the print dialog, mahasantri a plain link', function () {
    $semester = Semester::factory()->active()->create();
    $student = Student::factory()->create();
    app(ManageClassEnrollments::class)->enroll($semester, ClassGroup::factory()->create(), [$student->id]);

    $this->actingAs(User::factory()->withRole(Role::Admin)->create())
        ->get(route('admin.students.record', $student))
        ->assertOk()
        ->assertSee("\$dispatch('print-options'", false)
        ->assertSee(__('Without (sign and stamp by hand)'));

    $this->actingAs($student->user)
        ->get(route('mahasantri.transcript'))
        ->assertOk()
        ->assertDontSee('print-options', false);
});

test('only superadmin manages identity and signatures', function (Role $role) {
    $this->actingAs(User::factory()->withRole($role)->create());

    $this->get(route('superadmin.identity'))->assertForbidden();
    $this->get(route('superadmin.signatures'))->assertForbidden();
    $this->get(route('superadmin.identity.preview'))->assertForbidden();
})->with([Role::Admin, Role::AdminPayment, Role::Student]);
