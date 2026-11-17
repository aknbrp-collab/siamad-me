<?php

use App\Actions\Enrollment\ManageClassEnrollments;
use App\Actions\Enrollment\ManageCourseOfferings;
use App\Actions\Exams\ManageDispensationRequests;
use App\Actions\Exams\ManageExams;
use App\Actions\Finance\GenerateBills;
use App\Actions\Finance\RecordPayment;
use App\Actions\Finance\ReviewPayments;
use App\Enums\Gender;
use App\Enums\PrintDocument;
use App\Enums\Role;
use App\Models\Bill;
use App\Models\ClassGroup;
use App\Models\FeeRate;
use App\Models\FeeType;
use App\Models\Room;
use App\Models\Semester;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Support\DocxToPdf;
use App\Support\PrintTemplates;
use App\Support\Templates;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\TemplateProcessor;

/*
 * v7 D44: KHS, transcript, exam card, receipt, cash report and the dispensation request can be printed
 * from an uploaded Word template, like letters; without one the built-in PDF stays.
 */

beforeEach(function () {
    $this->semester = Semester::factory()->active()->create();
    $class = ClassGroup::factory()->create(['name' => 'Semester I Putra', 'level' => 1, 'gender' => Gender::Male]);
    $this->subject = Subject::factory()->create(['name' => 'Ushul Fiqh Dasar']);
    $this->offering = app(ManageCourseOfferings::class)->save($this->semester, null, [
        'subject_id' => $this->subject->id, 'class_group_id' => $class->id, 'teacher_id' => Teacher::factory()->create()->id,
    ]);

    $this->ali = Student::factory()->create(['nim' => '2026130001']);
    app(ManageClassEnrollments::class)->enroll($this->semester, $class, [$this->ali->id]);

    $spp = FeeType::factory()->create(['name' => 'SPP']);
    FeeRate::create(['fee_type_id' => $spp->id, 'academic_year_id' => $this->semester->academic_year_id, 'amount' => 1_500_000]);
    app(GenerateBills::class)->handle($this->semester, '2026-08-31');
    $this->bill = Bill::query()->where('student_id', $this->ali->id)->sole();

    $this->admin = User::factory()->withRole(Role::Admin)->create();
    fakePrintConverter(false);
});

/**
 * Pretend LibreOffice is (not) installed.
 */
function fakePrintConverter(bool $available): void
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

function usePrintTemplate(PrintDocument $document, User $by): void
{
    Templates::upload(PrintTemplates::key($document), new UploadedFile(PrintTemplates::defaultFile($document), $document->value.'.docx', null, null, true), $by);
}

/**
 * The text of a filled Word file, and the placeholders still in it.
 *
 * @return array{0: string, 1: list<string>}
 */
function printedWord(string $file): array
{
    $zip = new ZipArchive;
    $zip->open($file);
    $xml = (string) $zip->getFromName('word/document.xml');
    $zip->close();

    return [html_entity_decode(strip_tags(str_replace('</w:p>', "\n", $xml))), (new TemplateProcessor($file))->getVariables()];
}

test('every printout has a slot with a starting Word file using only its placeholders', function () {
    foreach (PrintTemplates::documents() as $document) {
        $key = PrintTemplates::key($document);
        expect(Templates::slots())->toHaveKey($key);

        $variables = (new TemplateProcessor(PrintTemplates::defaultFile($document)))->getVariables();
        expect(array_diff($variables, PrintTemplates::names($document)))->toBe([])
            ->and($variables)->toContain(...array_keys(PrintTemplates::rows($document)));

        usePrintTemplate($document, $this->admin);
        expect(PrintTemplates::uploaded($document))->not->toBeNull();
    }

    $this->actingAs($this->admin);
    Livewire::test('pages::templates')
        ->assertSee(__('Printouts'))
        ->assertSeeHtml('data-test="placeholders-print-khs"')
        ->assertSee('${sks_bobot}');
});

test('a template with an unknown placeholder is refused', function () {
    $word = new PhpWord;
    $word->addSection()->addText('${nama} ${nilai_akhlak}');
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.uniqid('khs').'.docx';
    IOFactory::createWriter($word, 'Word2007')->save($path);

    expect(fn () => Templates::upload('print.khs', new UploadedFile($path, 'khs.docx', null, null, true), $this->admin))
        ->toThrow(ValidationException::class, '${nilai_akhlak}');
});

test('the KHS comes from the template once uploaded: Word without LibreOffice, PDF with it', function () {
    $this->actingAs($this->admin)->withSession(['active_role' => Role::Admin->value]);

    $this->get(route('records.khs', [$this->ali, $this->semester]))->assertOk()->assertHeader('content-type', 'application/pdf');

    usePrintTemplate(PrintDocument::Khs, $this->admin);
    $word = $this->get(route('records.khs', [$this->ali, $this->semester]))->assertOk()->assertDownload('KHS-'.$this->ali->nim.'-'.$this->semester->id.'.docx');
    [$text, $left] = printedWord($word->baseResponse->getFile()->getPathname());

    expect($text)->toContain($this->ali->user->name)->toContain($this->ali->nim)->toContain('Ushul Fiqh Dasar')->toContain('belum final')
        ->and($left)->toBe([]);

    fakePrintConverter(true);
    $this->get(route('records.khs', [$this->ali, $this->semester]))->assertOk()->assertHeader('content-type', 'application/pdf');
    $this->get(route('records.khs', ['student' => $this->ali, 'semester' => $this->semester, 'format' => 'docx']))->assertDownload();
});

test('a transcript without final grades drops the empty row', function () {
    usePrintTemplate(PrintDocument::Transcript, $this->admin);
    $this->actingAs($this->admin)->withSession(['active_role' => Role::Admin->value]);

    [$text, $left] = printedWord($this->get(route('records.transcript', $this->ali))->assertOk()->baseResponse->getFile()->getPathname());

    expect($text)->toContain($this->ali->user->name)->not->toContain('Ushul Fiqh Dasar')
        ->and($left)->toBe([]);
});

test('the exam card from the template lists the subjects, room and seat', function () {
    $exams = app(ManageExams::class);
    $period = $exams->savePeriod($this->semester, null, ['type' => 'uts', 'starts_on' => '2026-11-02', 'ends_on' => '2026-11-07']);
    $exams->saveSession($period, null, ['course_offering_id' => $this->offering->id, 'held_on' => '2026-11-02', 'starts_at' => '08:00', 'ends_at' => '10:00', 'invigilator_id' => null]);
    $room = Room::create(['name' => 'R.101', 'capacity' => 30, 'gender' => Gender::Male]);
    $exams->setRooms($period, [$room->id]);
    $exams->assignSeats($period);
    $exams->grantDispensation($period, $this->ali, ['letter_number' => '015/R/2026', 'letter_date' => '2026-10-30'], $this->admin);
    usePrintTemplate(PrintDocument::ExamCard, $this->admin);

    $this->actingAs($this->admin)->withSession(['active_role' => Role::Admin->value]);
    [$text, $left] = printedWord($this->get(route('exams.card', [$period, $this->ali]))->assertOk()->baseResponse->getFile()->getPathname());

    expect($text)->toContain('Ushul Fiqh Dasar')->toContain('R.101')->toContain('015/R/2026')->toContain('08:00')
        ->and($left)->toBe([]);
});

test('only the verified receipt comes from the template; a pending slip keeps its watermark layout', function () {
    $finance = User::factory()->withRole(Role::AdminPayment)->create();
    // A transfer the finance office recorded is verified by an Admin.
    $checker = $this->admin;
    $payment = app(RecordPayment::class)->handle($this->bill, $finance, ['amount' => 500_000, 'method' => 'transfer', 'paid_on' => now()->toDateString(), 'reference' => 'TRX-9']);
    usePrintTemplate(PrintDocument::Receipt, $this->admin);

    $this->actingAs($finance)->withSession(['active_role' => Role::AdminPayment->value]);
    $this->get(route('receipts.show', $payment))->assertOk()->assertHeader('content-type', 'application/pdf');

    app(ReviewPayments::class)->verifyTransfer($payment, $checker);
    [$text, $left] = printedWord($this->get(route('receipts.show', $payment->fresh()))->assertOk()->baseResponse->getFile()->getPathname());

    expect($text)->toContain($payment->fresh()->receipt_number)->toContain('Rp 500.000')->toContain('TRX-9')->toContain($checker->name)
        ->and($left)->toBe([]);
});

test('the cash report from the template repeats income, expense and account rows', function () {
    usePrintTemplate(PrintDocument::CashReport, $this->admin);
    $finance = User::factory()->withRole(Role::AdminPayment)->create();

    $this->actingAs($finance)->withSession(['active_role' => Role::AdminPayment->value]);
    [$text, $left] = printedWord($this->get(route('finance.cash-report', ['from' => '2026-09', 'to' => '2026-10']))->assertOk()->baseResponse->getFile()->getPathname());

    expect($text)->toContain('LAPORAN KAS')->toContain('Pembayaran tagihan mahasantri')->toContain('September 2026')
        ->and($left)->toBe([]);
});

test('the dispensation request from the template carries the reason and bills, never signature images', function () {
    $request = app(ManageDispensationRequests::class)->submit($this->ali, "Menunggu panen.\nInsya Allah lunas Desember.", $this->ali->user);
    usePrintTemplate(PrintDocument::Dispensation, $this->admin);

    $this->actingAs($this->ali->user);
    $file = $this->get(route('dispensations.letter', $request))->assertOk()->baseResponse->getFile()->getPathname();
    [$text, $left] = printedWord($file);

    expect($text)->toContain('Menunggu panen.')->toContain('Insya Allah lunas Desember.')->toContain($this->bill->number)->toContain('Rp 1.500.000')
        ->and($left)->toBe([]);

    $zip = new ZipArchive;
    $zip->open($file);
    $images = collect(range(0, $zip->numFiles - 1))->map(fn (int $index) => $zip->getNameIndex($index))->filter(fn (string $name) => str_starts_with($name, 'word/media/'));
    $zip->close();
    // Only the logo of the letterhead (when there is one), no signature or stamp.
    expect($images->count())->toBeLessThanOrEqual(1);
});
