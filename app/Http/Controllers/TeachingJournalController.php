<?php

namespace App\Http\Controllers;

use App\Actions\Teaching\ManageTeachingDocuments;
use App\Enums\Role;
use App\Models\CourseOffering;
use App\Support\ActiveRole;
use App\Support\TeachingJournal;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The jurnal mengajar PDF of a class or of classes taught together, built
 * from the meetings, for the muhadhir to sign and upload (D13).
 */
class TeachingJournalController extends Controller
{
    public function __invoke(Request $request, CourseOffering $offering, ManageTeachingDocuments $documents): Response
    {
        $offering->loadMissing(['teacher.user', 'semester.academicYear']);

        $allowed = in_array(ActiveRole::get(), [Role::Admin, Role::Superadmin, Role::AcademicMonitor], true)
            || $offering->teacher->user_id === $request->user()->id;
        abort_unless($allowed, 403);

        $members = $documents->members($offering);

        return Pdf::loadView('records.teaching-journal', [
            'offering' => $offering,
            'members' => $members,
            'rows' => TeachingJournal::rows($members),
            'teacherName' => $offering->teacher->displayName(),
        ])->setPaper('a4', 'landscape')->stream('Jurnal-'.$offering->subject_code.'-'.$offering->semester_id.'.pdf');
    }
}
