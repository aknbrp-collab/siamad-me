<?php

namespace App\Http\Controllers;

use App\Actions\Evaluations\ManageEvaluations;
use App\Actions\Teaching\AcademicRecord;
use App\Enums\PrintDocument;
use App\Enums\Role;
use App\Models\Semester;
use App\Models\Student;
use App\Support\ActiveRole;
use App\Support\PrintMarks;
use App\Support\PrintTemplates;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * KHS and transcript as PDF, for the mahasantri, their wali santri and
 * academic staff (Admin, Superadmin, Academic Monitor).
 */
class AcademicRecordPdfController extends Controller
{
    public function khs(Request $request, Student $student, Semester $semester, AcademicRecord $record, ManageEvaluations $evaluations): Response|BinaryFileResponse
    {
        $this->authorizeView($request, $student);

        abort_if(
            $student->user_id === $request->user()->id && $evaluations->blocksKhs($student, $semester->id),
            403,
            __('The KHS of this semester opens once you have evaluated all your classes kuliah.'),
        );

        $khs = $record->khs($student, $semester);
        $marks = PrintMarks::for(PrintDocument::Khs, $request);
        $name = 'KHS-'.$student->nim.'-'.$semester->id;

        // From the Word template when one is uploaded (v7 D44).
        $word = PrintTemplates::respond(PrintDocument::Khs, PrintTemplates::khs($student, $semester->loadMissing('academicYear'), $khs), $marks, $name, $request->query('format') === 'docx');
        if ($word !== null) {
            return $word;
        }

        return Pdf::loadView('records.khs', [
            'student' => $student,
            'semester' => $semester->loadMissing('academicYear'),
            'khs' => $khs,
            'document' => PrintDocument::Khs,
            'marks' => $marks,
        ])->setPaper('a4')->stream($name.'.pdf');
    }

    public function transcript(Request $request, Student $student, AcademicRecord $record): Response|BinaryFileResponse
    {
        $this->authorizeView($request, $student);

        $transcript = $record->transcript($student);
        $marks = PrintMarks::for(PrintDocument::Transcript, $request);
        $name = 'Transkrip-'.$student->nim;

        $word = PrintTemplates::respond(PrintDocument::Transcript, PrintTemplates::transcript($student, $transcript), $marks, $name, $request->query('format') === 'docx');
        if ($word !== null) {
            return $word;
        }

        return Pdf::loadView('records.transcript', [
            'student' => $student,
            'transcript' => $transcript,
            'document' => PrintDocument::Transcript,
            'marks' => $marks,
        ])->setPaper('a4')->stream($name.'.pdf');
    }

    /**
     * Superadmin: the letterhead and a signature block as they will print,
     * with the default (or chosen) signatory and stamp of a document.
     */
    public function preview(Request $request): Response
    {
        $document = PrintDocument::tryFrom((string) $request->query('document')) ?? PrintDocument::Transcript;

        return Pdf::loadView('records.preview', [
            'document' => $document,
            'marks' => PrintMarks::for($document, $request),
        ])->setPaper('a4')->stream('Contoh-kop.pdf');
    }

    private function authorizeView(Request $request, Student $student): void
    {
        $student->loadMissing(['user', 'guardians', 'studyProgram']);
        $user = $request->user();

        $allowed = in_array(ActiveRole::get(), [Role::Admin, Role::Superadmin, Role::AcademicMonitor], true)
            || $student->user_id === $user->id
            || $student->guardians->contains('user_id', $user->id);

        abort_unless($allowed, 403);
    }
}
