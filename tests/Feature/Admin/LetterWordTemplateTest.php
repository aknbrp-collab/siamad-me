<?php

use App\Actions\Letters\ManageLetters;
use App\Enums\Role;
use App\Models\LetterType;
use App\Models\Semester;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use App\Support\DocxToPdf;
use App\Support\LetterDocuments;
use App\Support\Templates;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\TemplateProcessor;

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo(Carbon::parse('2026-10-28 09:00'));
    Setting::set('institution.name', "Ma'had Aly Contoh");
    Semester::factory()->active()->create();

    $this->admin = User::factory()->withRole(Role::Admin)->create();
    $this->student = Student::factory()->create(['nim' => '2026110001']);
    $this->ska = LetterType::query()->where('code', 'SKA')->sole();
    $this->letters = app(ManageLetters::class);
});

/**
 * The text of a .docx, for checking what was filled in.
 */
function wordText(string $path): string
{
    $zip = new ZipArchive;
    $zip->open($path);
    $xml = (string) $zip->getFromName('word/document.xml');
    $zip->close();

    return html_entity_decode(strip_tags(str_replace('<w:br/>', "\n", $xml)));
}

function uploadLetterTemplate(LetterType $type, User $by, string $marker = ''): void
{
    $path = LetterDocuments::defaultFile();
    Templates::upload("letter.{$type->id}", new UploadedFile($path, 'surat-aktif'.$marker.'.docx', null, null, true), $by);
}

/**
 * Pretend LibreOffice is (not) installed.
 */
function fakeConverter(bool $available): void
{
    app()->instance(DocxToPdf::class, new class($available) extends DocxToPdf
    {
        public function __construct(private bool $installed) {}

        public function available(): bool
        {
            return $this->installed;
        }

        public function convert(string $docx): string
        {
            $pdf = preg_replace('/\.docx$/', '.pdf', $docx);
            file_put_contents($pdf, '%PDF-1.4 fake');

            return $pdf;
        }
    });
}

test('a letter type without a Word template keeps the built-in PDF', function () {
    $letter = $this->letters->issue(['letter_type_id' => $this->ska->id, 'student_id' => $this->student->id, 'subject_line' => 'Surat aktif', 'body' => 'Isi.', 'issued_on' => '2026-10-28', 'print_marks' => true], $this->admin);

    expect($letter->document_template_id)->toBeNull();
    $this->actingAs($this->admin)->withSession(['active_role' => Role::Admin->value])
        ->get(route('letters.pdf', $letter))->assertOk()->assertHeader('content-type', 'application/pdf');
});

test('letters are filled from the Word template; Word without LibreOffice, PDF with it', function () {
    uploadLetterTemplate($this->ska, $this->admin);
    $letter = $this->letters->issue([
        'letter_type_id' => $this->ska->id, 'student_id' => $this->student->id, 'subject_line' => 'Surat Keterangan Aktif',
        'body' => "Yang bertanda tangan di bawah ini menerangkan bahwa\n{$this->student->user->name} adalah mahasantri aktif.", 'issued_on' => '2026-10-28', 'print_marks' => true,
    ], $this->admin);

    expect($letter->document_template_id)->toBe(Templates::current("letter.{$this->ska->id}")->id);

    $this->actingAs($this->admin)->withSession(['active_role' => Role::Admin->value]);

    fakeConverter(false);
    $word = $this->get(route('letters.pdf', $letter))->assertOk()->assertDownload('Surat-'.str_replace('/', '-', $letter->number).'.docx');
    $file = $word->baseResponse->getFile()->getPathname();

    expect(wordText($file))->toContain($letter->number)->toContain('Surat Keterangan Aktif')->toContain("Ma'had Aly Contoh")
        ->toContain($this->student->user->name.' adalah mahasantri aktif')
        ->and((new TemplateProcessor($file))->getVariables())->toBe([]);

    fakeConverter(true);
    $this->get(route('letters.pdf', $letter))->assertOk()->assertHeader('content-type', 'application/pdf');
    // Staff can still ask for the Word file.
    $this->get(route('letters.pdf', ['letter' => $letter, 'format' => 'docx']))->assertDownload();
});

test('a letter keeps the template version it was issued with', function () {
    uploadLetterTemplate($this->ska, $this->admin);
    $letter = $this->letters->issue(['letter_type_id' => $this->ska->id, 'student_id' => $this->student->id, 'subject_line' => 'Lama', 'body' => 'Isi.', 'issued_on' => '2026-10-28', 'print_marks' => true], $this->admin);
    $first = $letter->document_template_id;

    uploadLetterTemplate($this->ska, $this->admin, '-baru');
    Templates::revert("letter.{$this->ska->id}", $this->admin);
    $later = $this->letters->issue(['letter_type_id' => $this->ska->id, 'student_id' => $this->student->id, 'subject_line' => 'Baru', 'body' => 'Isi.', 'issued_on' => '2026-10-28', 'print_marks' => true], $this->admin);

    expect($letter->fresh()->document_template_id)->toBe($first)
        ->and($later->document_template_id)->toBeNull();
});

test('a letter template may only use letter placeholders', function () {
    $word = new PhpWord;
    $word->addSection()->addText('${nomor} ${mata_kuliah}');
    $path = sys_get_temp_dir().'/letter-'.uniqid().'.docx';
    IOFactory::createWriter($word, 'Word2007')->save($path);

    expect(fn () => Templates::upload("letter.{$this->ska->id}", new UploadedFile($path, 'x.docx', null, null, true), $this->admin))
        ->toThrow(ValidationException::class, '${mata_kuliah}');

    $this->actingAs($this->admin)->get(route('templates'))->assertOk()->assertSee($this->ska->name)->assertSee('${mahasantri.nama}', false);
});
