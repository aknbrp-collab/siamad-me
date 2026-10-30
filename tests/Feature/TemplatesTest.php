<?php

use App\Actions\Enrollment\ManageCourseOfferings;
use App\Actions\Teaching\ManageMeetings;
use App\Enums\Role;
use App\Models\ClassGroup;
use App\Models\DocumentTemplate;
use App\Models\Semester;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Support\SpreadsheetDownload;
use App\Support\TeachingTemplates;
use App\Support\Templates;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\TemplateProcessor;
use Spatie\SimpleExcel\SimpleExcelReader;

beforeEach(function () {
    Storage::fake('local');
    $this->admin = User::factory()->withRole(Role::Admin)->create();
});

/**
 * An uploadable copy of a file built in a test.
 */
function uploadOf(string $path, string $name): UploadedFile
{
    return new UploadedFile($path, $name, null, null, true);
}

test('the built-in import template has the columns, an example row and instructions', function () {
    $path = Templates::defaultFile('import.students');
    $reader = SimpleExcelReader::create($path);

    expect($reader->getHeaders())->toContain('nim*', 'name*')
        ->and($reader->getRows()->count())->toBe(1)
        ->and(SimpleExcelReader::create($path)->fromSheetName(__('Instructions'))->getRows()->count())->toBeGreaterThan(3);

    $this->actingAs($this->admin)->get(route('admin.imports.template', 'students'))->assertOk()->assertDownload();
});

test('a replacement is used for downloads until the built-in one is restored', function () {
    // A replacement that dropped a required column is refused.
    $broken = SpreadsheetDownload::templatePath('x', ['name' => __('Required. Full name.')], ['name' => 'Ahmad']);
    expect(fn () => Templates::upload('import.students', uploadOf($broken, 'students.xlsx'), $this->admin))
        ->toThrow(ValidationException::class, 'nim');

    $mine = Templates::defaultFile('import.students');
    $version = Templates::upload('import.students', uploadOf($mine, 'mahasantri-kami.xlsx'), $this->admin);

    expect($version->version)->toBe(1)
        ->and(Templates::file('import.students'))->toBe([Storage::disk('local')->path($version->file_path), false]);

    Templates::revert('import.students', $this->admin);
    expect(Templates::current('import.students')->file_path)->toBeNull()
        ->and(Templates::file('import.students')[1])->toBeTrue()
        ->and(DocumentTemplate::query()->count())->toBe(2);
});

test('word replacements may only use known placeholders', function () {
    $word = new PhpWord;
    $word->addSection()->addText('${mata_kuliah} ${nama_rektor}');
    $path = sys_get_temp_dir().'/unknown-'.uniqid().'.docx';
    IOFactory::createWriter($word, 'Word2007')->save($path);

    expect(fn () => Templates::upload('teaching.rps', uploadOf($path, 'rps.docx'), $this->admin))
        ->toThrow(ValidationException::class, '${nama_rektor}');
});

test('the muhadhir downloads the RPS and journal of a class, filled in', function () {
    $semester = Semester::factory()->active()->create();
    $teacher = Teacher::factory()->create();
    $class = ClassGroup::factory()->create(['name' => 'Semester I Putra']);
    $offering = app(ManageCourseOfferings::class)->save($semester, null, [
        'subject_id' => Subject::factory()->create(['code' => 'FQH101', 'name' => 'Fiqh Ibadah'])->id,
        'class_group_id' => $class->id, 'teacher_id' => $teacher->id,
    ]);
    app(ManageMeetings::class)->save($offering, null, ['held_on' => '2026-09-05', 'topic' => 'Thaharah', 'mode' => 'offline', 'method' => 'Bandongan']);
    app(ManageMeetings::class)->save($offering, null, ['held_on' => '2026-09-12', 'topic' => 'Wudhu', 'mode' => 'offline']);

    $this->actingAs($teacher->user)->withSession(['active_role' => Role::Teacher->value])
        ->get(route('ustadz.offerings.documents', $offering))->assertSeeHtml('data-test="template-journal"');

    $response = $this->get(route('teaching.template', [$offering, 'journal']))->assertOk();
    $file = $response->baseResponse->getFile()->getPathname();
    $text = (new ZipArchive)->open($file) === true ? (function () use ($file) {
        $zip = new ZipArchive;
        $zip->open($file);

        return strip_tags((string) $zip->getFromName('word/document.xml'));
    })() : '';

    expect($text)->toContain('Fiqh Ibadah (FQH101)')->toContain('Semester I Putra')->toContain('Thaharah')->toContain('Wudhu')->toContain('Bandongan')
        ->and((new TemplateProcessor($file))->getVariables())->toBe([]);

    $this->get(route('teaching.template', [$offering, 'rps']))->assertOk()->assertDownload();

    $this->actingAs(Teacher::factory()->create()->user)->withSession(['active_role' => Role::Teacher->value])
        ->get(route('teaching.template', [$offering, 'rps']))->assertForbidden();
});

test('the Templates page lists every slot for Admin and the monitor, not for others', function () {
    $this->actingAs($this->admin)->get(route('templates'))->assertOk()
        ->assertSee(__('RPS'))->assertSee(__('Teaching journal'))->assertSee('${mata_kuliah}', false);

    Livewire::test('pages::templates')
        ->set('uploads.teaching-rps', UploadedFile::fake()->create('rps.pdf', 10, 'application/pdf'))
        ->call('upload', 'teaching.rps')
        ->assertHasErrors('uploads.teaching-rps')
        ->set('uploads.teaching-rps', UploadedFile::fake()->createWithContent('rps-kami.docx', (string) file_get_contents(TeachingTemplates::defaultFile('rps'))))
        ->call('upload', 'teaching.rps')
        ->assertHasNoErrors()
        ->assertSee('rps-kami.docx')
        ->call('revert', 'teaching.rps')
        ->assertDontSee('rps-kami.docx');

    $this->get(route('templates.show', 'teaching.journal'))->assertOk()->assertDownload();

    $this->actingAs(User::factory()->withRole(Role::AcademicMonitor)->create())->get(route('templates'))->assertOk();
    $this->actingAs(User::factory()->withRole(Role::AdminPayment)->create())->get(route('templates'))->assertForbidden();
});
