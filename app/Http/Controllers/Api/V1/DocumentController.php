<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Documents\UploadDocument;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\StudentDocument;
use App\Models\TeacherDocument;
use App\Support\ActiveRole;
use App\Support\ApiFiles;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Dokumen saya: KTP, KK and ijazah of the mahasantri (or muhadhir), uploaded
 * for the academic office to verify.
 */
#[Group('Documents', weight: 7)]
class DocumentController extends Controller
{
    /**
     * My documents.
     *
     * Each with its status (missing, uploaded, verified, rejected with a note) and a link to the file.
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->documents($request)->map(fn (StudentDocument|TeacherDocument $document) => $this->present($request, $document))->values()->all()]);
    }

    /**
     * Upload a document.
     *
     * Multipart `file` (PDF or image). Ijazah also needs `education_level`, `institution`, `graduation_year`
     * (and `degree_title` for muhadhir). A verified document cannot be replaced.
     */
    public function upload(Request $request, int $document, UploadDocument $upload): JsonResponse
    {
        $request->validate(['file' => UploadDocument::rules()]);
        $item = $this->documents($request)->firstWhere('id', $document);
        abort_if($item === null, 404);
        abort_if($item->status === DocumentStatus::Verified, 422, __('This document is already verified.'));

        $upload->handle($item, $request->file('file'), $request->only(['education_level', 'institution', 'graduation_year', 'degree_title']));

        return response()->json(['data' => $this->present($request, $item->refresh())]);
    }

    /**
     * @return Collection<int, StudentDocument>|Collection<int, TeacherDocument>
     */
    private function documents(Request $request): Collection
    {
        $user = $request->user();
        $owner = ActiveRole::get() === Role::Teacher ? $user->teacher : $user->student;
        abort_if($owner === null, 403);

        return $owner->documents()->orderByRaw("case when type = 'ijazah' then 1 else 0 end")->orderBy('id')->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Request $request, StudentDocument|TeacherDocument $document): array
    {
        return [
            'id' => $document->id,
            'type' => $document->type->value,
            'title' => $document->title(),
            'status' => $document->status->value,
            'status_label' => $document->status->label(),
            'rejection_note' => $document->rejection_note,
            'education_level' => $document->education_level?->value,
            'institution' => $document->institution,
            'graduation_year' => $document->graduation_year,
            'degree_title' => $document->degree_title,
            'is_ijazah' => $document->type === DocumentType::Ijazah,
            'uploaded_at' => $document->uploaded_at?->toIso8601String(),
            'file_url' => $document->file_path === null ? null : ApiFiles::url(
                $request->user(), ActiveRole::get(), $document instanceof StudentDocument ? 'student-document' : 'teacher-document', $document->id,
            ),
        ];
    }
}
