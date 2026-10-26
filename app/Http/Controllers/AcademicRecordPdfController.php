<?php

namespace App\Http\Controllers;

use App\Actions\Teaching\AcademicRecord;
use App\Enums\Role;
use App\Models\Semester;
use App\Models\Setting;
use App\Models\Student;
use App\Support\ActiveRole;
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
            ...$this->common($student),
            'semester' => $semester->loadMissing('academicYear'),
            'khs' => $record->khs($student, $semester),
        ])->setPaper('a4')->stream('KHS-'.$student->nim.'-'.$semester->id.'.pdf');
    }

    public function transcript(Request $request, Student $student, AcademicRecord $record): Response
    {
        $this->authorizeView($request, $student);

        return Pdf::loadView('records.transcript', [
            ...$this->common($student),
            'transcript' => $record->transcript($student),
        ])->setPaper('a4')->stream('Transkrip-'.$student->nim.'.pdf');
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

    /**
     * @return array<string, mixed>
     */
    private function common(Student $student): array
    {
        return [
            'student' => $student,
            'institution' => [
                'name' => Setting::get('institution.name'),
                'address' => Setting::get('institution.address'),
            ],
        ];
    }
}
