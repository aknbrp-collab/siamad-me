<?php

namespace App\Http\Controllers;

use App\Actions\Teaching\ManageTeachingDocuments;
use App\Enums\Role;
use App\Models\CourseOffering;
use App\Support\ActiveRole;
use App\Support\TeachingTemplates;
use App\Support\Templates;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Downloads of the managed templates (v4 D24).
 */
class TemplateController extends Controller
{
    /**
     * A template as it is in use (blank), for the staff who manage templates.
     */
    public function show(string $key): BinaryFileResponse
    {
        return Templates::download($key);
    }

    /**
     * The RPS or jurnal template of a class, filled in with its details (and,
     * for the journal, its meetings): the muhadhir of the class, Admin,
     * Superadmin and the academic monitor.
     */
    public function teaching(Request $request, CourseOffering $offering, string $kind, ManageTeachingDocuments $documents): BinaryFileResponse
    {
        $offering->loadMissing('teacher');

        $allowed = in_array(ActiveRole::get(), [Role::Admin, Role::Superadmin, Role::AcademicMonitor], true)
            || $offering->teacher->user_id === $request->user()->id;
        abort_unless($allowed, 403);

        [$template, $temporary] = Templates::file("teaching.{$kind}");
        $filled = TeachingTemplates::fill($template, $kind, $documents->members($offering));

        if ($temporary) {
            @unlink($template);
        }

        $name = ($kind === 'journal' ? 'Jurnal' : 'RPS').'-'.Str::slug($offering->subject_code.' '.$offering->class_name).'.docx';

        return response()->download($filled, $name)->deleteFileAfterSend();
    }
}
