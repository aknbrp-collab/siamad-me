<?php

namespace App\Http\Controllers;

use App\Actions\Exams\ManageDispensationRequests;
use App\Enums\PrintDocument;
use App\Enums\Role;
use App\Models\DispensationRequest;
use App\Models\Guardian;
use App\Models\Setting;
use App\Support\ActiveRole;
use App\Support\PrintMarks;
use App\Support\PrintTemplates;
use App\Support\StudentAccess;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Surat permohonan dispensasi (v7 D47) as a PDF: the mahasantri, their wali, academic and finance staff.
 * The Mudir signs by hand, so no signature or stamp image is printed.
 */
class DispensationLetterController extends Controller
{
    public function __invoke(Request $request, DispensationRequest $dispensation, ManageDispensationRequests $requests): Response|BinaryFileResponse
    {
        $role = ActiveRole::get();
        $student = $dispensation->student;

        abort_unless($role === Role::AdminPayment || StudentAccess::canViewBills($request->user(), $role, $student), 403);

        $student->loadMissing(['user', 'studyProgram', 'guardians.user', 'classEnrollments' => fn ($query) => $query->where('semester_id', $dispensation->semester_id)]);
        $dispensation->loadMissing('semester.academicYear');
        $signatory = PrintMarks::defaults(PrintDocument::Dispensation)->signatory;
        $className = $student->classEnrollments->first()?->class_name;
        $guardians = $student->guardians->map(fn (Guardian $guardian) => $guardian->user->name)->join(', ');
        $bills = $requests->bills($student, $dispensation->semester);
        $head = $signatory->name ?? Setting::get('institution.head');
        $name = 'Permohonan-Dispensasi-'.$student->nim;

        // From the Word template when one is uploaded (v7 D44), without signature or stamp images.
        $word = PrintTemplates::respond(PrintDocument::Dispensation, PrintTemplates::dispensation($dispensation, $student, $className, $guardians, $bills, $head), new PrintMarks($signatory, null, false), $name, $request->query('format') === 'docx');
        if ($word !== null) {
            return $word;
        }

        return Pdf::loadView('records.dispensation', [
            'request' => $dispensation,
            'student' => $student,
            'className' => $className,
            'guardians' => $guardians,
            'bills' => $bills,
            'signatory' => $signatory,
            'head' => $head,
            'institution' => (string) Setting::get('institution.name'),
            'city' => Setting::get('institution.city'),
        ])->setPaper('a4', 'portrait')->stream($name.'.pdf');
    }
}
