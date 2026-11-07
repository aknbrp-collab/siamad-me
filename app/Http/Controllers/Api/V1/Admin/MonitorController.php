<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Teaching\ManageGrades;
use App\Actions\Teaching\ManageTeachingDocuments;
use App\Enums\Role;
use App\Enums\TeachingDocumentStatus;
use App\Http\Controllers\Controller;
use App\Models\CourseOffering;
use App\Models\Semester;
use App\Models\TeachingDocument;
use App\Support\ActiveRole;
use App\Support\ApiFiles;
use App\Support\MonitorReport;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Pemantauan (academic monitor, Admin, Superadmin): attendance, missing work
 * and grades per class kuliah, and the teaching documents (RPS, journal) with
 * their review. Unlocking grades and reviewing documents: Admin, Superadmin.
 */
#[Group('Admin akademik', weight: 15)]
class MonitorController extends Controller
{
    /**
     * Monitoring dashboard.
     *
     * For a semester (`?semester=`, default the active one): per class kuliah the meetings held, average
     * attendance, how many are under the minimum, missing submissions and whether grades are final; the
     * mahasantri under the minimum; the spread of final letters.
     */
    public function dashboard(Request $request): JsonResponse
    {
        $request->validate(['semester' => ['nullable', 'integer']]);
        $semesterId = $request->integer('semester') ?: (Semester::active()->id ?? Semester::query()->orderByDesc('id')->value('id'));
        $report = new MonitorReport($semesterId);
        $stats = $report->offeringStats();
        $missing = $report->missingWork();

        return response()->json(['data' => [
            'semester_id' => $semesterId,
            'min_attendance_percent' => $report->minimum,
            'classes' => $report->offerings->map(fn (CourseOffering $offering) => [
                'id' => $offering->id,
                'subject_name' => $offering->subject_name,
                'class_name' => $offering->class_name,
                'teacher' => $offering->teacher->user->name,
                'mahasantri_count' => $offering->enrollments->count(),
                'meetings_count' => (int) $offering->getAttribute('meetings_count'),
                'components_count' => (int) $offering->getAttribute('components_count'),
                'average_attendance' => $stats[$offering->id]['average'] ?? null,
                'below_minimum' => $stats[$offering->id]['below'] ?? 0,
                'missing_submissions' => $missing[$offering->id] ?? 0,
                'grades_final' => $offering->isFinalized(),
            ])->values()->all(),
            'below_minimum' => $report->belowMinimum()->all(),
            'grade_distribution' => $report->distribution(),
        ]]);
    }

    /**
     * Unlock final grades (Admin, Superadmin).
     *
     * With a `reason`; the muhadhir can correct and finalise again.
     */
    public function unlock(Request $request, CourseOffering $offering, ManageGrades $grades): Response
    {
        $this->ensureManager();
        $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $grades->unlock($offering, (string) $request->input('reason'));

        return response()->noContent();
    }

    /**
     * Teaching documents.
     *
     * Per class kuliah (or joint group) of a semester: the classes in it, the muhadhir, and the latest RPS and
     * journal with their status. `filter`: missing, waiting, returned or complete.
     */
    public function teachingDocuments(Request $request): JsonResponse
    {
        $request->validate(['semester' => ['nullable', 'integer'], 'filter' => ['nullable', Rule::in(['missing', 'waiting', 'returned', 'complete'])]]);
        $semesterId = $request->integer('semester') ?: (Semester::active()->id ?? Semester::query()->orderByDesc('id')->value('id'));
        $user = $request->user();
        $role = ActiveRole::get();

        $offerings = CourseOffering::query()->with(['teacher.user'])->where('semester_id', $semesterId)->orderBy('subject_name')->orderBy('id')->get();
        $latest = TeachingDocument::query()->whereIn('course_offering_id', $offerings->modelKeys())->orderByDesc('version')->get()
            ->unique(fn (TeachingDocument $document) => $document->course_offering_id.'|'.$document->kind->value)
            ->keyBy(fn (TeachingDocument $document) => $document->course_offering_id.'|'.$document->kind->value);

        $present = fn (?TeachingDocument $document) => $document === null ? null : [
            'id' => $document->id,
            'version' => $document->version,
            'status' => $document->status->value,
            'status_label' => $document->status->label(),
            'review_note' => $document->review_note,
            'file_url' => ApiFiles::url($user, $role, 'teaching-document', $document->id),
        ];

        $units = $offerings->whereNull('joined_to_id')->values()
            ->map(fn (CourseOffering $lead) => [
                'lead' => $lead,
                'members' => $offerings->filter(fn (CourseOffering $offering) => $offering->id === $lead->id || $offering->joined_to_id === $lead->id)->values(),
                'rps' => $latest->get($lead->id.'|rps'),
                'journal' => $latest->get($lead->id.'|journal'),
            ])
            ->filter(fn (array $unit) => match ($request->input('filter')) {
                'missing' => $unit['rps'] === null || $unit['journal'] === null,
                'waiting' => $unit['rps']?->status === TeachingDocumentStatus::Waiting || $unit['journal']?->status === TeachingDocumentStatus::Waiting,
                'returned' => $unit['rps']?->status === TeachingDocumentStatus::Returned || $unit['journal']?->status === TeachingDocumentStatus::Returned,
                'complete' => $unit['rps']?->status === TeachingDocumentStatus::Accepted && $unit['journal']?->status === TeachingDocumentStatus::Accepted,
                default => true,
            })->values();

        return response()->json(['data' => $units->map(fn (array $unit) => [
            'lead_id' => $unit['lead']->id,
            'subject_name' => $unit['lead']->subject_name,
            'teacher' => $unit['lead']->teacher->user->name,
            'classes' => $unit['members']->map(fn (CourseOffering $member) => ['id' => $member->id, 'class_name' => $member->class_name])->values()->all(),
            'rps' => $present($unit['rps']),
            'journal' => $present($unit['journal']),
            'journal_pdf_url' => ApiFiles::download($user, $role, 'teaching-journal', ['offering' => $unit['lead']->id]),
        ])->all()]);
    }

    /**
     * Review a teaching document (Admin, Superadmin).
     *
     * `accept: true`, or `false` to return it with a `note`; the muhadhir is notified.
     */
    public function review(Request $request, TeachingDocument $document, ManageTeachingDocuments $documents): Response
    {
        $this->ensureManager();
        $request->validate(['accept' => ['required', 'boolean'], 'note' => ['nullable', 'string', 'max:2000']]);
        $documents->review($document, $request->boolean('accept'), $request->input('note'), $request->user());

        return response()->noContent();
    }

    /**
     * Join classes taught together (Admin, Superadmin).
     *
     * `class_ids`: the lead class kuliah first, then the others (same subject and muhadhir); they share one RPS
     * and journal.
     */
    public function join(Request $request, ManageTeachingDocuments $documents): JsonResponse
    {
        $this->ensureManager();
        $request->validate(['class_ids' => ['required', 'array', 'min:2'], 'class_ids.*' => ['integer']]);
        $lead = $documents->join(array_values(array_map('intval', (array) $request->input('class_ids'))));

        return response()->json(['data' => ['lead_id' => $lead->id]]);
    }

    /**
     * Split a joint group (Admin, Superadmin).
     */
    public function split(CourseOffering $offering, ManageTeachingDocuments $documents): Response
    {
        $this->ensureManager();
        $documents->split($offering);

        return response()->noContent();
    }

    private function ensureManager(): void
    {
        abort_unless(in_array(ActiveRole::get(), [Role::Admin, Role::Superadmin], true), 403);
    }
}
