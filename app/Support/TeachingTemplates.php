<?php

namespace App\Support;

use App\Models\CourseOffering;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\SimpleType\TblWidth;
use PhpOffice\PhpWord\TemplateProcessor;

/**
 * The Word templates of the RPS and the jurnal mengajar (v4 Step 7). The
 * built-in defaults are designed here with PhpWord and carry placeholders
 * such as ${mata_kuliah}; an uploaded replacement may use the same ones.
 * For a class, the placeholders are filled in and the journal gets one row
 * per meeting.
 */
final class TeachingTemplates
{
    private const GREEN = '2E7D32';

    private const LIGHT = 'E8F5E9';

    private const LINE = 'A5D6A7';

    /**
     * Placeholders every teaching template can use, with what they hold.
     *
     * @return array<string, string>
     */
    public static function placeholders(): array
    {
        return [
            'institusi' => __('Institution name'),
            'alamat' => __('Institution address'),
            'mata_kuliah' => __('Subject'),
            'kode' => __('Subject code'),
            'sks' => __('SKS'),
            'kitab' => __('Kitab'),
            'kelas' => __('Class (all classes taught together)'),
            'muhadhir' => __('Muhadhir'),
            'semester' => __('Semester'),
            'kota' => __('City'),
            'tanggal' => __('Today\'s date'),
        ];
    }

    /**
     * Placeholders of one journal row; the row is repeated for every meeting.
     *
     * @return array<string, string>
     */
    public static function journalRow(): array
    {
        return [
            'no' => __('Meeting number'),
            'tgl' => __('Date'),
            'materi' => __('Material'),
            'metode' => __('Method'),
            'hadir' => __('Present / enrolled'),
            'catatan' => __('Note'),
        ];
    }

    /**
     * Build the default template of $kind ("rps" or "journal") into a temporary file.
     */
    public static function defaultFile(string $kind): string
    {
        $word = new PhpWord;
        $word->setDefaultFontName('Calibri');
        $word->setDefaultFontSize(10);

        $section = $word->addSection($kind === 'journal'
            ? ['orientation' => 'landscape', 'marginTop' => 800, 'marginBottom' => 800, 'marginLeft' => 900, 'marginRight' => 900]
            : ['marginTop' => 800, 'marginBottom' => 800, 'marginLeft' => 1000, 'marginRight' => 1000]);

        self::letterhead($section);
        $section->addText($kind === 'journal' ? 'JURNAL MENGAJAR' : 'RENCANA PEMBELAJARAN SEMESTER (RPS)', ['bold' => true, 'size' => 14, 'color' => self::GREEN], ['alignment' => Jc::CENTER, 'spaceAfter' => 200]);
        self::identity($section);

        $kind === 'journal' ? self::journalTable($section) : self::rpsBody($section);
        self::signature($section);

        $path = self::temporaryPath();
        IOFactory::createWriter($word, 'Word2007')->save($path);

        return $path;
    }

    /**
     * Fill $template (a .docx path) for a class or classes taught together.
     *
     * @param  EloquentCollection<int, CourseOffering>  $members  lead first
     */
    public static function fill(string $template, string $kind, EloquentCollection $members): string
    {
        $lead = $members->first();
        $lead->loadMissing(['subject', 'teacher.user', 'semester.academicYear']);

        $processor = new TemplateProcessor($template);
        $variables = $processor->getVariables();

        if ($kind === 'journal' && in_array('no', $variables, true)) {
            $rows = TeachingJournal::rows($members);
            $processor->cloneRow('no', max(1, count($rows)));

            foreach ($rows as $index => $row) {
                $n = $index + 1;
                $processor->setValue("no#{$n}", (string) $n);
                $processor->setValue("tgl#{$n}", $row['date']->translatedFormat('j M Y'));
                $processor->setValue("materi#{$n}", trim(($row['topic'] ?? '').($row['material'] ? ' — '.$row['material'] : '')));
                $processor->setValue("metode#{$n}", (string) ($row['method'] ?? ''));
                $processor->setValue("hadir#{$n}", $row['present'].'/'.$row['total']);
                $processor->setValue("catatan#{$n}", (string) ($row['note'] ?? ''));
            }

            if ($rows === []) {
                foreach (array_keys(self::journalRow()) as $field) {
                    $processor->setValue("{$field}#1", '');
                }
            }
        }

        $processor->setValues([
            'institusi' => (string) Setting::get('institution.name'),
            'alamat' => (string) Setting::get('institution.address'),
            'mata_kuliah' => $lead->subject_name,
            'kode' => $lead->subject_code,
            'sks' => (string) $lead->credits,
            'kitab' => (string) $lead->subject->kitab,
            'kelas' => $members->pluck('class_name')->join(', '),
            'muhadhir' => $lead->teacher->displayName(),
            'semester' => $lead->semester->label(),
            'kota' => (string) Setting::get('institution.city'),
            'tanggal' => now()->translatedFormat('j F Y'),
        ]);

        $path = self::temporaryPath();
        $processor->saveAs($path);

        return $path;
    }

    private static function letterhead(Section $section): void
    {
        $head = Branding::letterhead();
        $table = $section->addTable(['borderBottomSize' => 18, 'borderBottomColor' => self::GREEN, 'width' => 100 * 50, 'unit' => TblWidth::PERCENT]);
        $table->addRow();

        if ($head['logo'] !== null) {
            $table->addCell(1400, ['valign' => 'center'])->addImage($head['logo'], ['height' => 55]);
        }

        $cell = $table->addCell(null, ['valign' => 'center']);
        $centre = ['alignment' => Jc::CENTER, 'spaceAfter' => 0];

        if ($head['foundation']) {
            $cell->addText((string) $head['foundation'], ['size' => 9], $centre);
        }

        $cell->addText('${institusi}', ['bold' => true, 'size' => 15, 'color' => self::GREEN], $centre);
        $cell->addText('${alamat}', ['size' => 9], $centre);

        if ($head['contact'] !== '') {
            $cell->addText($head['contact'], ['size' => 8, 'color' => '616161'], $centre);
        }

        $section->addTextBreak(1, ['size' => 6]);
    }

    private static function identity(Section $section): void
    {
        $table = $section->addTable(['borderSize' => 6, 'borderColor' => self::LINE, 'cellMargin' => 70, 'width' => 100 * 50, 'unit' => TblWidth::PERCENT]);

        foreach ([['Mata kuliah', '${mata_kuliah} (${kode}), ${sks} SKS'], ['Kitab', '${kitab}'], ['Kelas', '${kelas}'], ['Muhadhir', '${muhadhir}'], ['Semester', '${semester}']] as [$label, $value]) {
            $table->addRow();
            $table->addCell(2600, ['bgColor' => self::LIGHT])->addText($label, ['bold' => true]);
            $table->addCell(null)->addText($value);
        }

        $section->addTextBreak(1, ['size' => 6]);
    }

    private static function rpsBody(Section $section): void
    {
        foreach (['Capaian pembelajaran mata kuliah', 'Deskripsi singkat mata kuliah'] as $heading) {
            $section->addText($heading, ['bold' => true, 'color' => self::GREEN]);
            $section->addText('…', ['color' => '9E9E9E']);
            $section->addTextBreak(1, ['size' => 6]);
        }

        $section->addText('Rencana per pertemuan', ['bold' => true, 'color' => self::GREEN]);
        $table = self::grid($section, [['Pertemuan', 1100], ['Kemampuan akhir yang diharapkan', 2600], ['Bahan kajian (bab kitab)', 2600], ['Metode', 1500], ['Penilaian', 1400]]);

        foreach (range(1, 16) as $meeting) {
            $table->addRow();
            $label = match ($meeting) {
                8 => '8 (UTS)',
                16 => '16 (UAS)',
                default => (string) $meeting,
            };
            $table->addCell(1100)->addText($label, null, ['alignment' => Jc::CENTER]);
            foreach ([2600, 2600, 1500, 1400] as $width) {
                $table->addCell($width)->addText('');
            }
        }

        $section->addTextBreak(1, ['size' => 6]);
        $section->addText('Referensi', ['bold' => true, 'color' => self::GREEN]);
        $section->addText('1. ${kitab}');
    }

    private static function journalTable(Section $section): void
    {
        $table = self::grid($section, [['No', 600], ['Tanggal', 1500], ['Materi yang disampaikan', 5200], ['Metode', 1900], ['Hadir', 1000], ['Catatan', 2600], ['Paraf', 1100]]);
        $table->addRow();

        foreach ([['${no}', 600], ['${tgl}', 1500], ['${materi}', 5200], ['${metode}', 1900], ['${hadir}', 1000], ['${catatan}', 2600], ['', 1100]] as [$value, $width]) {
            $table->addCell($width)->addText($value);
        }
    }

    /**
     * A bordered table with a green header row.
     *
     * @param  list<array{0: string, 1: int}>  $columns
     */
    private static function grid(Section $section, array $columns): Table
    {
        $table = $section->addTable(['borderSize' => 6, 'borderColor' => self::LINE, 'cellMargin' => 60, 'width' => 100 * 50, 'unit' => TblWidth::PERCENT]);
        $table->addRow(null, ['tblHeader' => true]);

        foreach ($columns as [$label, $width]) {
            $table->addCell($width, ['bgColor' => self::GREEN, 'valign' => 'center'])->addText($label, ['bold' => true, 'color' => 'FFFFFF'], ['alignment' => Jc::CENTER]);
        }

        return $table;
    }

    private static function signature(Section $section): void
    {
        $section->addTextBreak(1);
        $table = $section->addTable(['width' => 100 * 50, 'unit' => TblWidth::PERCENT]);
        $table->addRow();
        $table->addCell(null)->addText('');
        $cell = $table->addCell(4200);
        $cell->addText('${kota}, ${tanggal}', null, ['spaceAfter' => 0]);
        $cell->addText('Muhadhir,', null, ['spaceAfter' => 900]);
        $cell->addText('${muhadhir}', ['bold' => true]);
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
