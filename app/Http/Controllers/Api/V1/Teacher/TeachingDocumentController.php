<?php

namespace App\Http\Controllers\Api\V1\Teacher;

use App\Actions\Teaching\ManageTeachingDocuments;
use App\Enums\TeachingDocumentKind;
use App\Http\Controllers\Api\V1\Teacher\Concerns\TeachesOffering;
use App\Http\Controllers\Controller;
use App\Models\CourseOffering;
use App\Models\TeachingDocument;
use App\Support\ActiveRole;
use App\Support\ApiFiles;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Dokumen mengajar: the RPS and the signed journal recap of a class kuliah
 * (one set for classes taught together), reviewed by the Admin.
 */
#[Group('Muhadhir', weight: 11)]
class TeachingDocumentController extends Controller
{
    use TeachesOffering;

    /**
     * Teaching documents.
     *
     * Per kind (rps, journal): every uploaded version newest first with its review, the Word template filled
     * in for this class, and the journal PDF of the meetings.
     */
    public function show(Request $request, CourseOffering $offering, ManageTeachingDocuments $documents): JsonResponse
    {
        $this->authorizeOffering($request, $offering);
        $user = $request->user();
        $role = ActiveRole::get();

        $versions = TeachingDocument::query()->with('reviewer')->where('course_offering_id', $offering->teachingLeadId())->orderByDesc('version')->get();

        return response()->json(['data' => [
            'classes' => $documents->members($offering)->map(fn (CourseOffering $member) => ['id' => $member->id, 'class_name' => $member->class_name])->values()->all(),
            'journal_pdf_url' => ApiFiles::download($user, $role, 'teaching-journal', ['offering' => $offering->id]),
            'kinds' => array_map(fn (TeachingDocumentKind $kind) => [
                'kind' => $kind->value,
                'label' => $kind->label(),
                'template_url' => ApiFiles::download($user, $role, 'teaching-template', ['offering' => $offering->id, 'kind' => $kind->value]),
                'versions' => $versions->where('kind', $kind)->map(fn (TeachingDocument $document) => [
                    'id' => $document->id,
                    'version' => $document->version,
                    'file_name' => $document->original_name,
                    'file_url' => ApiFiles::url($user, $role, 'teaching-document', $document->id),
                    'status' => $document->status->value,
                    'status_label' => $document->status->label(),
                    'review_note' => $document->review_note,
                    'reviewed_by' => $document->reviewer?->name,
                    'uploaded_at' => $document->created_at->toIso8601String(),
                ])->values()->all(),
            ], TeachingDocumentKind::cases()),
        ]]);
    }

    /**
     * Upload a teaching document.
     *
     * Multipart `file` (PDF or Word) as a new version of `kind` (rps or journal); the Admin is notified.
     */
    public function upload(Request $request, CourseOffering $offering, ManageTeachingDocuments $documents): JsonResponse
    {
        $this->authorizeOffering($request, $offering);
        $request->validate(['kind' => ['required', Rule::enum(TeachingDocumentKind::class)], 'file' => ['required', 'file']]);

        $documents->upload($offering, TeachingDocumentKind::from((string) $request->input('kind')), $request->file('file'), $request->user());

        return $this->show($request, $offering, $documents);
    }
}
