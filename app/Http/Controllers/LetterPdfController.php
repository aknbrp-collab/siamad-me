<?php

namespace App\Http\Controllers;

use App\Enums\PrintDocument;
use App\Enums\Role;
use App\Models\Letter;
use App\Support\ActiveRole;
use App\Support\DocxToPdf;
use App\Support\LetterDocuments;
use App\Support\PrintMarks;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * An outgoing letter as PDF (or, for a letter made from a Word template while
 * LibreOffice is missing, as a Word file): Admin and Superadmin any letter (a cancelled one
 * marked as such), the mahasantri a letter about them that is not cancelled.
 * The signer and stamp are the letter's own, unless staff choose otherwise.
 */
class LetterPdfController extends Controller
{
    public function __invoke(Request $request, Letter $letter): Response|BinaryFileResponse
    {
        $letter->loadMissing(['type', 'student.user', 'teacher.user', 'signatory', 'stamp']);

        $staff = in_array(ActiveRole::get(), [Role::Admin, Role::Superadmin], true);
        $own = ! $letter->isCancelled() && $letter->student !== null && $letter->student->user_id === $request->user()->id;
        abort_unless($staff || $own, 403);

        $marks = PrintMarks::chosen(new PrintMarks(
            $letter->signatory?->is_active ? $letter->signatory : null,
            $letter->stamp?->is_active ? $letter->stamp : null,
            $letter->print_marks,
        ), $request);

        // Made from a Word template (v4 D19): PDF through LibreOffice, else the Word file itself.
        // A cancelled letter keeps the built-in layout, which marks it DIBATALKAN.
        if ($letter->document_template_id !== null && ! $letter->isCancelled()) {
            return $this->fromWord($letter, $marks, $request->query('format') === 'docx');
        }

        return Pdf::loadView('records.letter', [
            'letter' => $letter,
            'document' => PrintDocument::Letter,
            'marks' => $marks,
            'date' => $letter->issued_on,
        ])->setPaper('a4')->stream('Surat-'.str_replace('/', '-', $letter->number).'.pdf');
    }

    private function fromWord(Letter $letter, PrintMarks $marks, bool $wantsWord): BinaryFileResponse
    {
        $name = 'Surat-'.str_replace('/', '-', $letter->number);
        $docx = LetterDocuments::render($letter, $marks);
        $converter = app(DocxToPdf::class);

        if (! $wantsWord && $converter->available()) {
            try {
                $pdf = $converter->convert($docx);
                @unlink($docx);

                return response()->file($pdf, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$name.'.pdf"'])->deleteFileAfterSend();
            } catch (RuntimeException $exception) {
                report($exception);
            }
        }

        return response()->download($docx, $name.'.docx')->deleteFileAfterSend();
    }
}
