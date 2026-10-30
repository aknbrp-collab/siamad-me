<?php

namespace App\Support;

use App\Models\Letter;
use App\Models\LetterRequest;
use App\Models\Setting;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\SimpleType\TblWidth;
use PhpOffice\PhpWord\TemplateProcessor;
use RuntimeException;

/**
 * Letters made from a Word template (v4 D19). A letter type may have a .docx
 * on the Templates page; letters issued afterwards are filled from it
 * (${nomor}, ${isi}, ${mahasantri.nama}, the signature image ${ttd} …) and
 * turned into PDF by LibreOffice when it is installed. Types without one keep
 * the built-in PDF layout.
 */
final class LetterDocuments
{
    /**
     * Placeholders a letter template can use.
     *
     * @return array<string, string>
     */
    public static function placeholders(): array
    {
        $fromBody = collect(LetterTemplate::placeholders())->mapWithKeys(fn (string $label, string $name) => [trim($name, '{}') => $label])->all();

        return [
            'nomor' => __('Letter number'),
            'perihal' => __('Subject line (Perihal)'),
            'kepada' => __('Recipient (Kepada Yth.)'),
            'isi' => __('The letter text as issued'),
            'institusi' => __('Institution name'),
            'alamat' => __('Institution address'),
            'kota' => __('City'),
            'penandatangan' => __('Name of the signatory'),
            'jabatan' => __('Position of the signatory'),
            'ttd' => __('Signature image'),
            'stempel' => __('Stamp image'),
            ...$fromBody,
        ];
    }

    /**
     * A designed starting point (letterhead, number, subject, text, signature)
     * to download, adjust in Word and upload.
     */
    public static function defaultFile(): string
    {
        $word = new PhpWord;
        $word->setDefaultFontName('Calibri');
        $word->setDefaultFontSize(11);
        $section = $word->addSection(['marginTop' => 800, 'marginBottom' => 900, 'marginLeft' => 1300, 'marginRight' => 1100]);

        TeachingTemplates::letterhead($section);

        $meta = $section->addTable(['cellMargin' => 20]);
        foreach ([['Nomor', '${nomor}'], ['Lampiran', '-'], ['Perihal', '${perihal}']] as [$label, $value]) {
            $meta->addRow();
            $meta->addCell(1300)->addText($label, null, ['spaceAfter' => 0]);
            $meta->addCell(7000)->addText(': '.$value, $label === 'Perihal' ? ['bold' => true] : null, ['spaceAfter' => 0]);
        }

        $section->addTextBreak();
        $section->addText('Kepada Yth.', null, ['spaceAfter' => 0]);
        $section->addText('${kepada}', null, ['spaceAfter' => 0]);
        $section->addText('di tempat');
        $section->addTextBreak();
        $section->addText('${isi}', null, ['alignment' => Jc::BOTH]);

        $section->addTextBreak();
        $signature = $section->addTable(['width' => 100 * 50, 'unit' => TblWidth::PERCENT]);
        $signature->addRow();
        $signature->addCell(null)->addText('${stempel}');
        $cell = $signature->addCell(4300);
        $cell->addText('${kota}, ${tanggal}', null, ['spaceAfter' => 0]);
        $cell->addText('${jabatan},', null, ['spaceAfter' => 0]);
        $cell->addText('${ttd}', null, ['spaceAfter' => 0]);
        $cell->addText('${penandatangan}', ['bold' => true, 'underline' => 'single']);

        $path = self::temporaryPath();
        IOFactory::createWriter($word, 'Word2007')->save($path);

        return $path;
    }

    /**
     * Fill the letter's own template version into a temporary .docx.
     */
    public static function render(Letter $letter, PrintMarks $marks): string
    {
        $letter->loadMissing(['documentTemplate', 'student.user', 'teacher.user']);
        $file = $letter->documentTemplate?->file_path;

        if ($file === null || ! Storage::disk('local')->exists($file)) {
            throw new RuntimeException('This letter has no Word template.');
        }

        $processor = new TemplateProcessor(Storage::disk('local')->path($file));
        $purpose = $letter->letter_request_id ? LetterRequest::query()->whereKey($letter->letter_request_id)->value('purpose') : null;

        $values = collect(LetterTemplate::values($letter->student, $letter->teacher, $letter->issued_on, $purpose))
            ->mapWithKeys(fn (string $value, string $name) => [trim($name, '{}') => $value])
            ->merge([
                'nomor' => $letter->number,
                'perihal' => $letter->subject_line,
                'kepada' => (string) $letter->recipient,
                'institusi' => (string) Setting::get('institution.name'),
                'alamat' => (string) Setting::get('institution.address'),
                'kota' => (string) Setting::get('institution.city'),
                'penandatangan' => (string) $marks->signatory?->name,
                'jabatan' => (string) $marks->signatory?->position,
            ]);

        self::image($processor, 'ttd', $marks->signatureFile(), 150, 65);
        self::image($processor, 'stempel', $marks->stampFile(), 110, 110);

        // The text keeps its lines and paragraphs.
        $text = new TextRun;
        foreach (preg_split('/\R/', trim($letter->body)) ?: [] as $index => $line) {
            if ($index > 0) {
                $text->addTextBreak();
            }
            $text->addText($line);
        }
        if (in_array('isi', $processor->getVariables(), true)) {
            $processor->setComplexValue('isi', $text);
        }

        $processor->setValues($values->all());

        $path = self::temporaryPath();
        $processor->saveAs($path);

        return $path;
    }

    private static function image(TemplateProcessor $processor, string $name, ?string $file, int $width, int $height): void
    {
        if (! in_array($name, $processor->getVariables(), true)) {
            return;
        }

        $file === null
            ? $processor->setValue($name, '')
            : $processor->setImageValue($name, ['path' => $file, 'width' => $width, 'height' => $height, 'ratio' => true]);
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
