<?php

namespace App\Http\Controllers;

use App\Actions\Exams\ExamEligibility;
use App\Enums\PrintDocument;
use App\Enums\Role;
use App\Models\ExamPeriod;
use App\Models\Student;
use App\Support\ActiveRole;
use App\Support\PrintMarks;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

/**
 * The exam card (kartu ujian) PDF. A mahasantri prints their own once the
 * period is published and the requirements are met. Admin can print any
 * card once seats are assigned; for a mahasantri who does not meet the
 * requirements, only with a dispensation (the rector's letter).
 */
class ExamCardController extends Controller
{
    public function __invoke(Request $request, ExamPeriod $period, Student $student, ExamEligibility $eligibility): Response
    {
        $student->loadMissing(['user', 'studyProgram', 'classEnrollments' => fn ($query) => $query->where('semester_id', $period->semester_id)]);
        $status = $eligibility->check($period, $student);

        $staff = in_array(ActiveRole::get(), [Role::Admin, Role::Superadmin], true);
        $own = $student->user_id === $request->user()->id;

        abort_unless($staff || ($own && $period->isPublished()), 403);
        abort_unless($status['eligible'], 403, __('The requirements for the exam card are not met.'));

        $subjects = collect($status['subjects'])->where('ok', true)->values();
        abort_if($subjects->contains(fn (array $subject) => $subject['seat'] === null), 403, __('Seats have not been assigned yet.'));

        $photo = $student->photo_path && Storage::disk('local')->exists($student->photo_path)
            ? Storage::disk('local')->path($student->photo_path)
            : null;

        return Pdf::loadView('records.exam-card', [
            'period' => $period->loadMissing('semester.academicYear'),
            'student' => $student,
            'className' => $student->classEnrollments->first()?->class_name,
            'subjects' => $subjects,
            'dispensation' => $status['dispensation'],
            'photo' => $photo,
            'document' => PrintDocument::ExamCard,
            'marks' => PrintMarks::for(PrintDocument::ExamCard, $request),
        ])->setPaper('a5', 'portrait')->stream('Kartu-Ujian-'.$period->type->short().'-'.$student->nim.'.pdf');
    }
}
