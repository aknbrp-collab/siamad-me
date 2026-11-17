<?php

namespace App\Support;

use App\Enums\PrintDocument;
use App\Models\Bill;
use App\Models\CashAccount;
use App\Models\DispensationRequest;
use App\Models\ExamDispensation;
use App\Models\ExamPeriod;
use App\Models\ExamSeat;
use App\Models\ExamSession;
use App\Models\Payment;
use App\Models\Semester;
use App\Models\Setting;
use App\Models\Student;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\SimpleType\TblWidth;
use PhpOffice\PhpWord\TemplateProcessor;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * @phpstan-type GradeRow array{enrollment_id: int, code: string, name: string, credits: int, score: string|null, letter: string|null, point: string|null, final: bool}
 * @phpstan-type Content array{0: array<string, string>, 1: array<string, list<array<string, string>>>, 2: array<string, string|null>}
 *
 * Printouts from Word templates (v7 D44): KHS, transcript, exam card, receipt, cash report and the
 * dispensation request, like letters (v4 D19). Each has a slot on /templates ("print.{document}") with a
 * designed starting Word file. Without an uploaded replacement the built-in PDF is used; with one, the
 * printout is filled from it (${nama}, a table row repeated per subject …) and turned into PDF by
 * LibreOffice, or handed out as Word when LibreOffice is missing.
 */
final class PrintTemplates
{
    private const GREEN = '2E7D32';

    private const LIGHT = 'E8F5E9';

    private const LINE = 'A5D6A7';

    private const BLANK_LINE = '____________________';

    /** Placeholders filled with an image. */
    private const IMAGES = ['ttd' => [150, 65], 'stempel' => [110, 110], 'foto' => [90, 120]];

    /** Placeholders that may hold several lines. */
    private const MULTILINE = ['alasan', 'ips_semester', 'catatan', 'rincian'];

    /**
     * Printouts with a Word template slot (letters have one per letter type).
     *
     * @return list<PrintDocument>
     */
    public static function documents(): array
    {
        return [PrintDocument::Khs, PrintDocument::Transcript, PrintDocument::ExamCard, PrintDocument::Receipt, PrintDocument::CashReport, PrintDocument::Dispensation];
    }

    public static function key(PrintDocument $document): string
    {
        return 'print.'.$document->value;
    }

    /**
     * Placeholders every printout can use.
     *
     * @return array<string, string>
     */
    public static function common(): array
    {
        return [
            'institusi' => __('Institution name'),
            'alamat' => __('Institution address'),
            'kota' => __('City'),
            'tanggal' => __('Date of the printout'),
            'jabatan' => __('Position of the signatory'),
            'penandatangan' => __('Name of the signatory'),
            'ttd' => __('Signature image'),
            'stempel' => __('Stamp image'),
        ];
    }

    /**
     * The printout's own placeholders (besides the common ones and the table rows).
     *
     * @return array<string, string>
     */
    public static function placeholders(PrintDocument $document): array
    {
        $student = ['nama' => __('Name'), 'nim' => __('NIM'), 'prodi' => __('Study program')];

        return match ($document) {
            PrintDocument::Khs => [...$student, 'semester' => __('Semester'), 'jumlah_sks' => __('Credits with a final grade'), 'ips' => __('Semester GPA (IPS)')],
            PrintDocument::Transcript => [...$student, 'angkatan' => __('Entry year'), 'total_sks' => __('Total credits'), 'ipk' => __('Cumulative GPA (IPK)'), 'ips_semester' => __('IPS of each semester, one per line')],
            PrintDocument::ExamCard => [...$student, 'ujian' => __('Exam period (UTS / UAS)'), 'semester' => __('Semester'), 'kelas' => __('Class'), 'ruang' => __('Room'), 'kursi' => __('Seat number'), 'dispensasi' => __('Dispensation letter, if any'), 'foto' => __('Photo')],
            PrintDocument::Receipt => ['no_kwitansi' => __('Receipt number'), 'nama' => __('Name'), 'nim' => __('NIM'), 'no_tagihan' => __('Bill number'), 'semester' => __('Semester'), 'jumlah' => __('Amount'), 'terbilang' => __('Amount in words'), 'rincian' => __('Fees paid, one per line'), 'cara_bayar' => __('Payment method'), 'tanggal_bayar' => __('Date paid'), 'sisa_tagihan' => __('Still to pay on the bill'), 'diverifikasi_oleh' => __('Verified by'), 'catatan' => __('Receipt footer')],
            PrintDocument::CashReport => ['periode' => __('Period'), 'saldo_per' => __('Balance date'), 'jumlah_pemasukan' => __('Total income'), 'jumlah_pengeluaran' => __('Total expense'), 'surplus' => __('Surplus / (deficit)'), 'jumlah_saldo' => __('Total balance'), 'catatan' => __('Note on unbooked payments')],
            PrintDocument::Dispensation => [...$student, 'kelas' => __('Class'), 'semester' => __('Semester'), 'wali' => __('Wali santri'), 'hp' => __('Phone'), 'alasan' => __('Reason'), 'jumlah_sisa' => __('Total still to pay'), 'tanggal_permohonan' => __('Date of the request')],
            PrintDocument::Letter => [],
        };
    }

    /**
     * Table rows repeated for each item, by the placeholder that marks the row (put all of a row's
     * placeholders in one table row).
     *
     * @return array<string, array<string, string>>
     */
    public static function rows(PrintDocument $document): array
    {
        return match ($document) {
            PrintDocument::Khs => ['no' => ['no' => __('Number'), 'kode' => __('Subject code'), 'mata_kuliah' => __('Subject'), 'sks' => __('SKS'), 'nilai' => __('Score'), 'huruf' => __('Letter grade'), 'bobot' => __('Grade point'), 'sks_bobot' => __('SKS × grade point')]],
            PrintDocument::Transcript => ['smt' => ['smt' => __('Semester'), 'kode' => __('Subject code'), 'mata_kuliah' => __('Subject'), 'sks' => __('SKS'), 'huruf' => __('Letter grade'), 'bobot' => __('Grade point')]],
            PrintDocument::ExamCard => ['mk' => ['mk' => __('Subject'), 'waktu' => __('Day and time')]],
            PrintDocument::Receipt, PrintDocument::Letter => [],
            PrintDocument::CashReport => [
                'pemasukan' => ['pemasukan' => __('Income category'), 'pemasukan_jumlah' => __('Amount')],
                'pengeluaran' => ['pengeluaran' => __('Expense category'), 'pengeluaran_jumlah' => __('Amount')],
                'rekening' => ['rekening' => __('Account'), 'saldo' => __('Balance')],
            ],
            PrintDocument::Dispensation => ['tagihan_semester' => ['tagihan_semester' => __('Semester of the bill'), 'no_tagihan' => __('Bill number'), 'tagihan' => __('Bill total'), 'dibayar' => __('Paid'), 'sisa' => __('Still to pay')]],
        };
    }

    /**
     * Every placeholder name a template of $document may use.
     *
     * @return list<string>
     */
    public static function names(PrintDocument $document): array
    {
        return array_values(array_unique([
            ...array_keys(self::common()),
            ...array_keys(self::placeholders($document)),
            ...collect(self::rows($document))->flatMap(fn (array $row) => array_keys($row))->all(),
        ]));
    }

    /**
     * The uploaded replacement in use, or null for the built-in PDF.
     */
    public static function uploaded(PrintDocument $document): ?string
    {
        $file = Templates::current(self::key($document))?->file_path;

        return $file !== null && Storage::disk('local')->exists($file) ? Storage::disk('local')->path($file) : null;
    }

    /**
     * The printout from the uploaded template (PDF, or Word without LibreOffice or when asked for), or null
     * when there is none and the built-in PDF should be used.
     *
     * @param  Content  $content  values, rows, images
     */
    public static function respond(PrintDocument $document, array $content, PrintMarks $marks, string $name, bool $wantsWord): ?BinaryFileResponse
    {
        $template = self::uploaded($document);

        if ($template === null) {
            return null;
        }

        [$values, $rows, $images] = $content;
        $docx = self::fill($template, [...self::signatureValues($document, $marks), ...$values], $rows, [
            'ttd' => $marks->signatureFile(),
            'stempel' => $marks->stampFile(),
            ...$images,
        ]);

        return app(DocxToPdf::class)->respond($docx, $name, $wantsWord);
    }

    /**
     * Fill a template into a temporary .docx.
     *
     * @param  array<string, string>  $values
     * @param  array<string, list<array<string, string>>>  $rows  by the placeholder that marks the row
     * @param  array<string, string|null>  $images  image files (null leaves the place empty)
     */
    public static function fill(string $template, array $values, array $rows, array $images): string
    {
        $processor = new TemplateProcessor($template);
        $variables = $processor->getVariables();

        foreach ($rows as $marker => $items) {
            if (! in_array($marker, $variables, true)) {
                continue;
            }

            if ($items === []) {
                $processor->deleteRow($marker);

                continue;
            }

            $processor->cloneRow($marker, count($items));
            foreach ($items as $index => $item) {
                foreach ($item as $name => $value) {
                    $processor->setValue($name.'#'.($index + 1), $value);
                }
            }
        }

        foreach ($images as $name => $file) {
            if (! in_array($name, $variables, true)) {
                continue;
            }

            [$width, $height] = self::IMAGES[$name] ?? [120, 120];
            $file === null
                ? $processor->setValue($name, '')
                : $processor->setImageValue($name, ['path' => $file, 'width' => $width, 'height' => $height, 'ratio' => true]);
        }

        // Text with several lines keeps them.
        foreach (self::MULTILINE as $name) {
            if (! array_key_exists($name, $values) || ! in_array($name, $variables, true)) {
                continue;
            }

            $text = new TextRun;
            foreach (preg_split('/\R/', trim($values[$name])) ?: [] as $index => $line) {
                if ($index > 0) {
                    $text->addTextBreak();
                }
                $text->addText($line);
            }
            $processor->setComplexValue($name, $text);
            unset($values[$name]);
        }

        $processor->setValues([
            'institusi' => (string) Setting::get('institution.name'),
            'alamat' => (string) Setting::get('institution.address'),
            'kota' => (string) Setting::get('institution.city'),
            'tanggal' => now()->translatedFormat('j F Y'),
            ...$values,
        ]);

        $path = self::temporaryPath();
        $processor->saveAs($path);

        return $path;
    }

    /**
     * @return array<string, string>
     */
    private static function signatureValues(PrintDocument $document, PrintMarks $marks): array
    {
        return [
            'jabatan' => $marks->signatory->position ?? $document->fallbackTitle(),
            // Without a signatory, a line to sign on, as in the built-in PDF.
            'penandatangan' => $marks->signatory->name ?? self::BLANK_LINE,
        ];
    }

    // --- What each printout fills in -------------------------------------------------------------------

    /**
     * @param  array{rows: Collection<int, GradeRow>, credits: int, quality: float, ip: float|null}  $khs
     * @return Content
     */
    public static function khs(Student $student, Semester $semester, array $khs): array
    {
        $student->loadMissing(['user', 'studyProgram']);

        return [[
            ...self::studentValues($student),
            'semester' => $semester->label(),
            'jumlah_sks' => (string) $khs['credits'],
            'ips' => $khs['ip'] !== null ? number_format($khs['ip'], 2) : '—',
        ], ['no' => array_values($khs['rows']->values()->map(fn (array $row, int $index) => [
            'no' => (string) ($index + 1),
            'kode' => (string) $row['code'],
            'mata_kuliah' => (string) $row['name'],
            'sks' => (string) $row['credits'],
            'nilai' => $row['final'] ? (string) $row['score'] : 'belum final',
            'huruf' => $row['final'] ? (string) $row['letter'] : '',
            'bobot' => $row['final'] ? (string) $row['point'] : '',
            'sks_bobot' => $row['final'] ? number_format($row['credits'] * (float) $row['point'], 2) : '',
        ])->all())], []];
    }

    /**
     * @param  array{semesters: Collection<int, array{semester: Semester, rows: Collection<int, GradeRow>, credits: int, ip: float|null}>, credits: int, ipk: float|null}  $transcript
     * @return Content
     */
    public static function transcript(Student $student, array $transcript): array
    {
        $student->loadMissing(['user', 'studyProgram']);
        $rows = [];
        $perSemester = [];

        foreach ($transcript['semesters'] as $entry) {
            $label = $entry['semester']->label();
            $perSemester[] = $label.': IPS '.number_format((float) $entry['ip'], 2).' ('.$entry['credits'].' SKS)';

            foreach ($entry['rows'] as $row) {
                $rows[] = [
                    'smt' => $label,
                    'kode' => (string) $row['code'],
                    'mata_kuliah' => (string) $row['name'],
                    'sks' => (string) $row['credits'],
                    'huruf' => (string) $row['letter'],
                    'bobot' => (string) $row['point'],
                ];
            }
        }

        return [[
            ...self::studentValues($student),
            'angkatan' => (string) $student->entry_year,
            'total_sks' => (string) $transcript['credits'],
            'ipk' => $transcript['ipk'] !== null ? number_format($transcript['ipk'], 2) : '—',
            'ips_semester' => implode("\n", $perSemester),
        ], ['smt' => $rows], []];
    }

    /**
     * @param  Collection<int, array{session: ExamSession, attendance: int|null, ok: bool}>  $subjects
     * @return Content
     */
    public static function examCard(ExamPeriod $period, Student $student, ?string $className, Collection $subjects, ExamSeat $seat, ?ExamDispensation $dispensation, ?string $photo): array
    {
        $student->loadMissing(['user', 'studyProgram']);

        return [[
            ...self::studentValues($student),
            'ujian' => $period->type->label(),
            'semester' => $period->semester->label(),
            'kelas' => (string) ($className ?? '—'),
            'ruang' => $seat->room->name,
            'kursi' => (string) $seat->seat_number,
            'dispensasi' => $dispensation ? 'Dispensasi berdasarkan surat no. '.$dispensation->letter_number.' tanggal '.$dispensation->letter_date->translatedFormat('j F Y') : '',
        ], ['mk' => array_values($subjects->map(fn (array $subject) => [
            'mk' => $subject['session']->courseOffering->subject_name,
            'waktu' => $subject['session']->held_on->translatedFormat('l, j M').' '.substr($subject['session']->starts_at, 0, 5).'–'.substr($subject['session']->ends_at, 0, 5),
        ])->all())], ['foto' => $photo]];
    }

    /**
     * @return Content
     */
    public static function receipt(Payment $payment, ?string $footer): array
    {
        $bill = $payment->bill;

        return [[
            'no_kwitansi' => (string) $payment->receipt_number,
            'nama' => $bill->student->user->name,
            'nim' => $bill->student->nim,
            'no_tagihan' => $bill->number,
            'semester' => $bill->semester->label(),
            'jumlah' => Money::format($payment->amount),
            'terbilang' => Str::ucfirst(Money::inWords($payment->amount)),
            'rincian' => $payment->allocations->sortBy('bill_line_id')->map(fn ($allocation) => $allocation->line->description.': '.Money::format($allocation->amount))->join('
'),
            'cara_bayar' => $payment->method->label().($payment->reference ? ' · '.$payment->reference : ''),
            'tanggal_bayar' => $payment->paid_on->translatedFormat('j F Y'),
            'sisa_tagihan' => Money::format($bill->outstanding()),
            'diverifikasi_oleh' => (string) ($payment->verifier ?? $payment->recorder)->name,
            'catatan' => (string) $footer,
            'tanggal' => ($payment->verified_at ?? $payment->created_at)->translatedFormat('j F Y'),
        ], [], []];
    }

    /**
     * @param  array{tuition: int, income: Collection<string, int>, expense: Collection<string, int>, total_in: int, total_out: int, unassigned: int}  $summary
     * @param  Collection<int, array{account: CashAccount, balance: int}>  $balances
     * @return Content
     */
    public static function cashReport(Carbon $from, Carbon $to, array $summary, Collection $balances): array
    {
        $line = fn (string $name, int $amount, string $label, string $total) => [$label => $name, $total => Money::format($amount)];

        return [[
            'periode' => $from->translatedFormat('F Y').($from->isSameMonth($to) ? '' : ' – '.$to->translatedFormat('F Y')),
            'saldo_per' => $to->translatedFormat('j F Y'),
            'jumlah_pemasukan' => Money::format($summary['total_in']),
            'jumlah_pengeluaran' => Money::format($summary['total_out']),
            'surplus' => Money::format($summary['total_in'] - $summary['total_out']),
            'jumlah_saldo' => Money::format((int) $balances->sum('balance')),
            'catatan' => $summary['unassigned'] > 0 ? 'Catatan: '.Money::format($summary['unassigned']).' pembayaran tagihan pada periode ini belum dibukukan ke rekening mana pun dan tidak termasuk dalam saldo.' : '',
        ], [
            'pemasukan' => [
                $line('Pembayaran tagihan mahasantri', $summary['tuition'], 'pemasukan', 'pemasukan_jumlah'),
                ...array_values($summary['income']->map(fn (int $amount, string $name) => $line($name, $amount, 'pemasukan', 'pemasukan_jumlah'))->all()),
            ],
            'pengeluaran' => array_values($summary['expense']->map(fn (int $amount, string $name) => $line($name, $amount, 'pengeluaran', 'pengeluaran_jumlah'))->all()),
            'rekening' => array_values($balances->map(fn (array $row) => ['rekening' => $row['account']->label(), 'saldo' => Money::format($row['balance'])])->all()),
        ], []];
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, Bill>  $bills
     * @return Content
     */
    public static function dispensation(DispensationRequest $request, Student $student, ?string $className, string $guardians, iterable $bills, ?string $head): array
    {
        $bills = collect($bills);

        return [[
            ...self::studentValues($student),
            'kelas' => (string) ($className ?? $student->gender->classLabel()),
            'semester' => $request->semester->label(),
            'wali' => $guardians,
            'hp' => (string) $student->phone,
            'alasan' => $request->reason,
            'jumlah_sisa' => Money::format((int) $bills->sum(fn (Bill $bill) => $bill->outstanding())),
            'tanggal_permohonan' => $request->updated_at->translatedFormat('j F Y'),
            'tanggal' => $request->updated_at->translatedFormat('j F Y'),
            'penandatangan' => filled($head) ? (string) $head : self::BLANK_LINE,
        ], ['tagihan_semester' => array_values($bills->map(fn (Bill $bill) => [
            'tagihan_semester' => $bill->semester->label(),
            'no_tagihan' => $bill->number,
            'tagihan' => Money::format($bill->net_amount),
            'dibayar' => Money::format($bill->paid_amount),
            'sisa' => Money::format($bill->outstanding()),
        ])->all())], []];
    }

    /**
     * @return array<string, string>
     */
    private static function studentValues(Student $student): array
    {
        return ['nama' => $student->user->name, 'nim' => $student->nim, 'prodi' => $student->studyProgram->name];
    }

    // --- The designed starting points --------------------------------------------------------------------

    /**
     * Build the starting Word file of $document into a temporary file.
     */
    public static function defaultFile(PrintDocument $document): string
    {
        $word = new PhpWord;
        $word->setDefaultFontName('Calibri');
        $word->setDefaultFontSize(10);
        $section = $word->addSection(['marginTop' => 700, 'marginBottom' => 800, 'marginLeft' => 1000, 'marginRight' => 1000]);

        TeachingTemplates::letterhead($section);

        match ($document) {
            PrintDocument::Khs => self::khsLayout($section),
            PrintDocument::Transcript => self::transcriptLayout($section),
            PrintDocument::ExamCard => self::examCardLayout($section),
            PrintDocument::Receipt => self::receiptLayout($section),
            PrintDocument::CashReport => self::cashReportLayout($section),
            PrintDocument::Dispensation => self::dispensationLayout($section),
            PrintDocument::Letter => null,
        };

        $path = self::temporaryPath();
        IOFactory::createWriter($word, 'Word2007')->save($path);

        return $path;
    }

    private static function khsLayout(Section $section): void
    {
        self::title($section, 'KARTU HASIL STUDI (KHS)');
        self::identity($section, [['Nama', '${nama}'], ['NIM', '${nim}'], ['Program studi', '${prodi}'], ['Semester', '${semester}']]);
        self::grid($section, [['No', 700], ['Kode', 1100], ['Mata kuliah', 3200], ['SKS', 700], ['Nilai', 850], ['Huruf', 850], ['Bobot', 900], ['SKS × Bobot', 1400]],
            ['${no}', '${kode}', '${mata_kuliah}', '${sks}', '${nilai}', '${huruf}', '${bobot}', '${sks_bobot}']);
        self::summary($section, [['Jumlah SKS (nilai final)', '${jumlah_sks}'], ['Indeks Prestasi Semester (IPS)', '${ips}']]);
        self::signature($section);
    }

    private static function transcriptLayout(Section $section): void
    {
        self::title($section, 'TRANSKRIP NILAI');
        self::identity($section, [['Nama', '${nama}'], ['NIM', '${nim}'], ['Program studi', '${prodi}'], ['Angkatan', '${angkatan}']]);
        self::grid($section, [['Semester', 2200], ['Kode', 1100], ['Mata kuliah', 3600], ['SKS', 700], ['Huruf', 900], ['Bobot', 900]],
            ['${smt}', '${kode}', '${mata_kuliah}', '${sks}', '${huruf}', '${bobot}']);
        self::summary($section, [['Total SKS', '${total_sks}'], ['Indeks Prestasi Kumulatif (IPK)', '${ipk}']]);
        $section->addText('IPS per semester', ['bold' => true, 'color' => self::GREEN], ['spaceBefore' => 120, 'spaceAfter' => 0]);
        $section->addText('${ips_semester}', ['size' => 9]);
        self::signature($section);
    }

    private static function examCardLayout(Section $section): void
    {
        self::title($section, 'KARTU UJIAN ${ujian}');
        $section->addText('${semester}', ['color' => '616161'], ['alignment' => Jc::CENTER, 'spaceAfter' => 120]);

        $table = $section->addTable(['width' => 100 * 50, 'unit' => TblWidth::PERCENT]);
        $table->addRow();
        $left = $table->addCell(7200);
        foreach ([['Nama', '${nama}'], ['NIM', '${nim}'], ['Program studi', '${prodi}'], ['Kelas', '${kelas}'], ['Ruang · kursi', '${ruang} · ${kursi}']] as [$label, $value]) {
            $run = $left->addTextRun(['spaceAfter' => 40]);
            $run->addText($label.': ', ['color' => '616161']);
            $run->addText($value, ['bold' => $label === 'Ruang · kursi']);
        }
        $table->addCell(2000, ['valign' => 'top'])->addText('${foto}', null, ['alignment' => Jc::RIGHT]);

        $section->addTextBreak(1, ['size' => 6]);
        self::grid($section, [['Mata kuliah', 5600], ['Waktu', 4200]], ['${mk}', '${waktu}']);
        $section->addText('${dispensasi}', ['italic' => true, 'size' => 9], ['spaceBefore' => 100]);
        $section->addText('Bawa kartu ini setiap ujian. Ruang dan nomor kursi sama untuk semua mata kuliah.', ['size' => 9, 'color' => '616161']);
        self::signature($section);
    }

    private static function receiptLayout(Section $section): void
    {
        self::title($section, 'KWITANSI PEMBAYARAN');
        self::identity($section, [
            ['No. kwitansi', '${no_kwitansi}'],
            ['Telah terima dari', '${nama} (NIM ${nim})'],
            ['Untuk pembayaran', 'Tagihan ${no_tagihan} · ${semester}'],
            ['Rincian', '${rincian}'],
            ['Sejumlah', '${jumlah}'],
            ['Terbilang', '${terbilang}'],
            ['Cara bayar', '${cara_bayar} · ${tanggal_bayar}'],
            ['Sisa tagihan', '${sisa_tagihan}'],
        ]);
        self::signature($section, 'Diverifikasi oleh ${diverifikasi_oleh}');
        $section->addText('${catatan}', ['size' => 8, 'color' => '757575'], ['spaceBefore' => 200]);
    }

    private static function cashReportLayout(Section $section): void
    {
        self::title($section, 'LAPORAN KAS');
        $section->addText('${periode}', ['color' => '616161'], ['alignment' => Jc::CENTER, 'spaceAfter' => 120]);

        foreach ([['Pemasukan', 'pemasukan', 'Jumlah pemasukan', '${jumlah_pemasukan}'], ['Pengeluaran', 'pengeluaran', 'Jumlah pengeluaran', '${jumlah_pengeluaran}']] as [$heading, $marker, $totalLabel, $total]) {
            $section->addText($heading, ['bold' => true, 'color' => self::GREEN], ['spaceAfter' => 40]);
            $table = self::grid($section, [['Kategori', 7200], ['Jumlah', 2600]], ['${'.$marker.'}', '${'.$marker.'_jumlah}']);
            self::totalRow($table, $totalLabel, $total, 7200, 2600);
            $section->addTextBreak(1, ['size' => 6]);
        }

        self::summary($section, [['Surplus / (defisit)', '${surplus}']]);
        $section->addText('Saldo per ${saldo_per}', ['bold' => true, 'color' => self::GREEN], ['spaceBefore' => 120, 'spaceAfter' => 40]);
        $table = self::grid($section, [['Rekening', 7200], ['Saldo', 2600]], ['${rekening}', '${saldo}']);
        self::totalRow($table, 'Jumlah saldo', '${jumlah_saldo}', 7200, 2600);
        $section->addText('${catatan}', ['size' => 8, 'color' => '757575'], ['spaceBefore' => 80]);
        self::signature($section);
    }

    private static function dispensationLayout(Section $section): void
    {
        self::title($section, 'SURAT PERMOHONAN DISPENSASI');
        $section->addText('Kepada Yth.', null, ['spaceAfter' => 0]);
        $section->addText('${jabatan} ${institusi}', null, ['spaceAfter' => 0]);
        $section->addText('di tempat');
        $section->addText('Assalamu\'alaikum warahmatullahi wabarakatuh.');
        $section->addText('Yang bertanda tangan di bawah ini:');
        self::identity($section, [['Nama', '${nama}'], ['NIM', '${nim}'], ['Program studi', '${prodi}'], ['Kelas', '${kelas}'], ['Semester', '${semester}'], ['Wali santri', '${wali}'], ['No. HP', '${hp}']]);
        $section->addText('mengajukan permohonan dispensasi atas administrasi (tagihan) yang belum dapat saya selesaikan, agar tetap dapat mengikuti kegiatan akademik dan ujian ${semester}. Rincian tagihan saya:', null, ['alignment' => Jc::BOTH]);
        $table = self::grid($section, [['Semester', 2400], ['No. tagihan', 2000], ['Tagihan', 1800], ['Dibayar', 1800], ['Sisa', 1800]],
            ['${tagihan_semester}', '${no_tagihan}', '${tagihan}', '${dibayar}', '${sisa}']);
        self::totalRow($table, 'Jumlah yang belum dibayar', '${jumlah_sisa}', 8000, 1800, 4);
        $section->addText('Alasan', ['bold' => true, 'color' => self::GREEN], ['spaceBefore' => 160, 'spaceAfter' => 40]);
        $section->addText('${alasan}');
        $section->addText('Demikian permohonan ini saya sampaikan. Atas perhatian dan kebijaksanaan ${jabatan}, saya ucapkan terima kasih.', null, ['spaceBefore' => 120, 'alignment' => Jc::BOTH]);
        $section->addText('Wassalamu\'alaikum warahmatullahi wabarakatuh.');

        // The Mudir signs by hand: no signature or stamp image.
        $section->addTextBreak(1);
        $table = $section->addTable(['width' => 100 * 50, 'unit' => TblWidth::PERCENT]);
        $table->addRow();
        $left = $table->addCell(4900);
        $left->addText('', null, ['spaceAfter' => 0]);
        $left->addText('', null, ['spaceAfter' => 0]);
        $left->addText('Pemohon,', null, ['alignment' => Jc::CENTER, 'spaceAfter' => 1000]);
        $left->addText('${nama}', ['bold' => true, 'underline' => 'single'], ['alignment' => Jc::CENTER, 'spaceAfter' => 0]);
        $left->addText('NIM ${nim}', null, ['alignment' => Jc::CENTER]);
        $right = $table->addCell(4900);
        $right->addText('${kota}, ${tanggal}', null, ['alignment' => Jc::CENTER, 'spaceAfter' => 0]);
        $right->addText('Menyetujui,', null, ['alignment' => Jc::CENTER, 'spaceAfter' => 0]);
        $right->addText('${jabatan}', null, ['alignment' => Jc::CENTER, 'spaceAfter' => 1000]);
        $right->addText('${penandatangan}', ['bold' => true, 'underline' => 'single'], ['alignment' => Jc::CENTER]);
    }

    private static function title(Section $section, string $title): void
    {
        $section->addText($title, ['bold' => true, 'size' => 13, 'color' => self::GREEN], ['alignment' => Jc::CENTER, 'spaceAfter' => 120]);
    }

    /**
     * @param  list<array{0: string, 1: string}>  $lines
     */
    private static function identity(Section $section, array $lines): void
    {
        $table = $section->addTable(['borderSize' => 6, 'borderColor' => self::LINE, 'cellMargin' => 50, 'width' => 100 * 50, 'unit' => TblWidth::PERCENT]);

        foreach ($lines as [$label, $value]) {
            $table->addRow();
            $table->addCell(2600, ['bgColor' => self::LIGHT])->addText($label, ['bold' => true], ['spaceAfter' => 0]);
            $table->addCell(7200)->addText($value, null, ['spaceAfter' => 0]);
        }

        $section->addTextBreak(1, ['size' => 6]);
    }

    /**
     * A bordered table with a green header row and one row of placeholders (repeated when filled).
     *
     * @param  list<array{0: string, 1: int}>  $columns
     * @param  list<string>  $row
     */
    private static function grid(Section $section, array $columns, array $row): Table
    {
        $table = $section->addTable(['borderSize' => 6, 'borderColor' => self::LINE, 'cellMargin' => 50, 'width' => 100 * 50, 'unit' => TblWidth::PERCENT]);
        $table->addRow(null, ['tblHeader' => true]);

        foreach ($columns as [$label, $width]) {
            $table->addCell($width, ['bgColor' => self::GREEN, 'valign' => 'center'])->addText($label, ['bold' => true, 'color' => 'FFFFFF'], ['alignment' => Jc::CENTER, 'spaceAfter' => 0]);
        }

        $table->addRow();
        foreach ($columns as $index => [, $width]) {
            $table->addCell($width)->addText($row[$index], null, ['spaceAfter' => 0]);
        }

        return $table;
    }

    /**
     * A shaded total under a grid: the label over all columns but the last (widths as in the grid).
     */
    private static function totalRow(Table $table, string $label, string $value, int $labelWidth, int $valueWidth, int $span = 1): void
    {
        $table->addRow();
        $table->addCell($labelWidth, ['bgColor' => self::LIGHT, 'gridSpan' => $span])->addText($label, ['bold' => true], ['spaceAfter' => 0]);
        $table->addCell($valueWidth, ['bgColor' => self::LIGHT])->addText($value, ['bold' => true], ['alignment' => Jc::END, 'spaceAfter' => 0]);
    }

    /**
     * @param  list<array{0: string, 1: string}>  $lines
     */
    private static function summary(Section $section, array $lines): void
    {
        $section->addTextBreak(1, ['size' => 6]);
        foreach ($lines as [$label, $value]) {
            $run = $section->addTextRun(['spaceAfter' => 0]);
            $run->addText($label.': ');
            $run->addText($value, ['bold' => true]);
        }
    }

    private static function signature(Section $section, ?string $note = null): void
    {
        $section->addTextBreak(1);
        $table = $section->addTable(['width' => 100 * 50, 'unit' => TblWidth::PERCENT]);
        $table->addRow();
        $table->addCell(5500)->addText('${stempel}');
        $cell = $table->addCell(4300);
        $cell->addText('${kota}, ${tanggal}', null, ['spaceAfter' => 0]);
        $cell->addText('${jabatan},', null, ['spaceAfter' => 0]);
        $cell->addText('${ttd}', null, ['spaceAfter' => 0]);
        $cell->addText('${penandatangan}', ['bold' => true, 'underline' => 'single'], ['spaceAfter' => 0]);
        if ($note !== null) {
            $cell->addText($note, ['size' => 8, 'color' => '757575']);
        }
    }

    private static function temporaryPath(): string
    {
        $directory = SpreadsheetDownload::directory();

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        return $directory.DIRECTORY_SEPARATOR.Str::uuid().'.docx';
    }
}
