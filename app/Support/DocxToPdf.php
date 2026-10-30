<?php

namespace App\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Turns a Word file into a PDF with LibreOffice (v4 D19). The program is
 * found at config('siakad.libreoffice') or in its usual places; without it,
 * letters made from a Word template are handed out as Word files.
 */
class DocxToPdf
{
    public function available(): bool
    {
        return $this->binary() !== null;
    }

    public function binary(): ?string
    {
        $candidates = array_filter([
            config('siakad.libreoffice'),
            'C:\\Program Files\\LibreOffice\\program\\soffice.exe',
            'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.exe',
            '/usr/bin/soffice',
            '/usr/bin/libreoffice',
            '/usr/local/bin/soffice',
            '/Applications/LibreOffice.app/Contents/MacOS/soffice',
        ]);

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Convert $docx and return the path of the PDF (next to it).
     */
    public function convert(string $docx): string
    {
        $binary = $this->binary() ?? throw new RuntimeException('LibreOffice is not installed.');
        $directory = dirname($docx);
        // A profile per run: two conversions at once would otherwise fight over one.
        $profile = $directory.DIRECTORY_SEPARATOR.'lo-'.Str::uuid();

        $process = new Process([
            $binary,
            '-env:UserInstallation=file:///'.ltrim(str_replace('\\', '/', $profile), '/'),
            '--headless', '--norestore', '--convert-to', 'pdf', '--outdir', $directory, $docx,
        ]);
        $process->setTimeout(90);
        $process->run();

        $pdf = $directory.DIRECTORY_SEPARATOR.pathinfo($docx, PATHINFO_FILENAME).'.pdf';
        File::deleteDirectory($profile);

        if (! $process->isSuccessful() || ! is_file($pdf)) {
            throw new RuntimeException('LibreOffice could not convert the letter: '.trim($process->getErrorOutput()));
        }

        return $pdf;
    }
}
