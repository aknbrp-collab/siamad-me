<?php

namespace App\Http\Controllers;

use App\Enums\PrintDocument;
use App\Enums\Role;
use App\Models\Letter;
use App\Support\ActiveRole;
use App\Support\PrintMarks;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * An outgoing letter as PDF: Admin and Superadmin any letter (a cancelled one
 * marked as such), the mahasantri a letter about them that is not cancelled.
 * The signer and stamp are the letter's own, unless staff choose otherwise.
 */
class LetterPdfController extends Controller
{
    public function __invoke(Request $request, Letter $letter): Response
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

        return Pdf::loadView('records.letter', [
            'letter' => $letter,
            'document' => PrintDocument::Letter,
            'marks' => $marks,
            'date' => $letter->issued_on,
        ])->setPaper('a4')->stream('Surat-'.str_replace('/', '-', $letter->number).'.pdf');
    }
}
