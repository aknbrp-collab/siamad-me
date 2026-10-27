<?php

namespace App\Http\Controllers;

use App\Actions\Teaching\AcademicRecord;
use App\Enums\PrintDocument;
use App\Enums\Role;
use App\Models\Semester;
use App\Models\Student;
use App\Support\ActiveRole;
use App\Support\PrintMarks;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * KHS and transcript as PDF, for the mahasantri, their wali santri and
 * academic staff (Admin, Superadmin, Academic Monitor).
 */
class AcademicRecordPdfController extends Controller
{
    public function khs(Request $request, Student $student, Semester $semester, AcademicRecord $record): Response
    {
        $this->authorizeView($request, $student);

        return Pdf::loadView('records.khs', [
            'student' => $student,
            'semester' => $semester->loadMissing('academicYear'),
            'khs' => $record->khs($student, $semester),
            'document' => PrintDocument::Khs,
            'marks' => PrintMarks::for(PrintDocument::Khs, $request),
        ])->setPaper('a4')->stream('KHS-'.$student->nim.'-'.$semester->id.'.pdf');
    }

    public function transcript(Request $request, Student $student, AcademicRecord $record): Response
    {
        $this->authorizeView($request, $student);

        return Pdf::loadView('records.transcript', [
            'student' => $student,
            'transcript' => $record->transcript($student),
            'document' => PrintDocument::Transcript,
            'marks' => PrintMarks::for(PrintDocument::Transcript, $request),
        ])->setPaper('a4')->stream('Transkrip-'.$student->nim.'.pdf');
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
