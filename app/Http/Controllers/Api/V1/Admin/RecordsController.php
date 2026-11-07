<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Documents\ReviewDocument;
use App\Actions\Lms\RecordActivity;
use App\Actions\Supervision\ManageSupervision;
use App\Enums\Role;
use App\Enums\StudentStatus;
use App\Enums\TeacherStatus;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\ClassEnrollment;
use App\Models\Semester;
use App\Models\Student;
use App\Models\StudentDocument;
use App\Models\Supervision;
use App\Models\Teacher;
use App\Models\TeacherDocument;
use App\Support\ActiveRole;
use App\Support\ApiFiles;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Personal documents to verify, pembimbing akademik assignments (Admin,
 * Superadmin) and the pesantren activity log (also the academic monitor).
 */
#[Group('Admin akademik', weight: 15)]
class RecordsController extends Controller
{
    /**
     * Documents to verify.
     *
     * `owner`: students (default) or teachers; `status` (uploaded, verified, rejected, missing); `type`
     * (ktp, kk, ijazah); `search` on NIM/NIDM or name. Oldest upload first, paginated, with counts per status.
     */
    public function documents(Request $request): JsonResponse
    {
        $request->validate(['owner' => ['nullable', Rule::in(['students', 'teachers'])], 'status' => ['nullable', 'string'], 'type' => ['nullable', 'string'], 'search' => ['nullable', 'string']]);
        $teachers = $request->input('owner') === 'teachers';
        $model = $teachers ? TeacherDocument::class : StudentDocument::class;
        $relation = $teachers ? 'teacher' : 'student';
        $idColumn = $teachers ? 'nidm' : 'nim';
        $term = '%'.mb_strtolower(trim((string) $request->input('search'))).'%';

        $documents = $model::query()->with(["{$relation}.user"])
            ->when($request->filled('status'), fn (Builder $query) => $query->where('status', $request->input('status')))
            ->when($request->filled('type'), fn (Builder $query) => $query->where('type', $request->input('type')))
            ->when($request->filled('search'), fn (Builder $query) => $query->whereHas($relation, fn (Builder $query) => $query->whereRaw("lower({$idColumn}) like ?", [$term])
                ->orWhereHas('user', fn (Builder $query) => $query->whereRaw('lower(name) like ?', [$term]))))
            ->orderByRaw('uploaded_at is null')->orderBy('uploaded_at')->orderBy('id')
            ->paginate(25);
        $counts = $model::query()->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status')->map(fn ($count) => (int) $count)->all();
        $user = $request->user();
        $role = ActiveRole::get();

        return response()->json([
            'data' => collect($documents->items())->map(function (StudentDocument|TeacherDocument $document) use ($teachers, $user, $role) {
                $owner = $document instanceof TeacherDocument ? $document->teacher : $document->student;

                return [
                    'id' => $document->id,
                    'owner' => $teachers ? 'teacher' : 'student',
                    'owner_id' => $owner->id,
                    'owner_number' => $owner instanceof Teacher ? $owner->nidm : $owner->nim,
                    'owner_name' => $owner->user->name,
                    'type' => $document->type->value,
                    'title' => $document->title(),
                    'status' => $document->status->value,
                    'status_label' => $document->status->label(),
                    'rejection_note' => $document->rejection_note,
                    'uploaded_at' => $document->uploaded_at?->toIso8601String(),
                    'file_url' => $document->file_path === null ? null : ApiFiles::url($user, $role, $teachers ? 'teacher-document' : 'student-document', $document->id),
                ];
            })->values()->all(),
            'counts' => $counts,
            'meta' => ['current_page' => $documents->currentPage(), 'last_page' => $documents->lastPage(), 'total' => $documents->total()],
        ]);
    }

    /**
     * Verify or reject a document.
     *
     * `owner` students or teachers; `verify: true`, or `false` with a `note` telling the owner what to fix.
     */
    public function reviewDocument(Request $request, int $document, ReviewDocument $review): Response
    {
        $request->validate(['owner' => ['required', Rule::in(['students', 'teachers'])], 'verify' => ['required', 'boolean'], 'note' => ['nullable', 'string', 'max:1000']]);
        $item = $request->input('owner') === 'teachers' ? TeacherDocument::query()->findOrFail($document) : StudentDocument::query()->findOrFail($document);

        $request->boolean('verify')
            ? $review->verify($item, $request->user())
            : $review->reject($item, $request->user(), (string) $request->input('note'));

        return response()->noContent();
    }

    /**
     * Pembimbing akademik.
     *
     * Active muhadhir with how many they supervise, and how many active mahasantri have no supervisor.
     * `?teacher=` also lists that muhadhir's supervisees.
     */
    public function supervision(Request $request): JsonResponse
    {
        $request->validate(['teacher' => ['nullable', 'integer']]);
        $semester = Semester::active();
        $classes = $semester === null ? collect() : ClassEnrollment::query()->where('semester_id', $semester->id)->pluck('class_name', 'student_id');

        $teachers = Teacher::query()->with('user')->withCount(['supervisions as supervisees_count' => fn ($query) => $query->whereNull('ended_on')])
            ->where('status', TeacherStatus::Active)->get()->sortBy('user.name')->values();

        $supervisees = ! $request->filled('teacher') ? null : Supervision::query()->current()->with('student.user')
            ->where('teacher_id', $request->integer('teacher'))->get()->sortBy('student.user.name')->values()
            ->map(fn (Supervision $supervision) => [
                'supervision_id' => $supervision->id,
                'student_id' => $supervision->student_id,
                'nim' => $supervision->student->nim,
                'name' => $supervision->student->user->name,
                'class_name' => $classes[$supervision->student_id] ?? null,
                'since' => $supervision->started_on->toDateString(),
            ])->all();

        return response()->json(['data' => [
            'unsupervised' => Student::query()->where('status', StudentStatus::Active)->doesntHave('currentSupervision')->count(),
            'teachers' => $teachers->map(fn (Teacher $teacher) => [
                'id' => $teacher->id,
                'name' => $teacher->user->name,
                'supervisees_count' => (int) $teacher->getAttribute('supervisees_count'),
            ])->values()->all(),
            'supervisees' => $supervisees,
        ]]);
    }

    /**
     * Assign supervisees.
     *
     * `student_ids` to `teacher_id`; a mahasantri with another supervisor moves. Returns how many were assigned.
     */
    public function assignSupervision(Request $request, ManageSupervision $manage): JsonResponse
    {
        $request->validate(['teacher_id' => ['required', 'integer'], 'student_ids' => ['required', 'array'], 'student_ids.*' => ['integer']]);
        $assigned = $manage->assign(Teacher::query()->findOrFail($request->integer('teacher_id')), array_values(array_map('intval', (array) $request->input('student_ids'))), $request->user());

        return response()->json(['data' => ['assigned' => $assigned]]);
    }

    /**
     * End a supervision.
     */
    public function endSupervision(Supervision $supervision, ManageSupervision $manage): Response
    {
        $manage->end($supervision);

        return response()->noContent();
    }

    /**
     * Pesantren activities.
     *
     * Activities recorded for mahasantri (`search` on NIM or name, `category`), newest first, paginated.
     */
    public function activities(Request $request): JsonResponse
    {
        $request->validate(['search' => ['nullable', 'string'], 'category' => ['nullable', 'string']]);
        $term = '%'.mb_strtolower(trim((string) $request->input('search'))).'%';

        $logs = ActivityLog::query()->with(['student.user', 'recorder'])
            ->when($request->filled('category'), fn (Builder $query) => $query->where('category', $request->input('category')))
            ->when($request->filled('search'), fn (Builder $query) => $query->whereHas('student', fn (Builder $query) => $query->whereRaw('lower(nim) like ?', [$term])
                ->orWhereHas('user', fn (Builder $query) => $query->whereRaw('lower(name) like ?', [$term]))))
            ->latest('happened_on')->latest('id')->paginate(25);

        return response()->json([
            'data' => collect($logs->items())->map(fn (ActivityLog $log) => [
                'id' => $log->id,
                'student_id' => $log->student_id,
                'nim' => $log->student->nim,
                'name' => $log->student->user->name,
                'category' => $log->category->value,
                'category_label' => $log->category->label(),
                'happened_on' => $log->happened_on->toDateString(),
                'title' => $log->title,
                'notes' => $log->notes,
                'recorded_by' => $log->recorder->name,
            ])->values()->all(),
            'meta' => ['current_page' => $logs->currentPage(), 'last_page' => $logs->lastPage(), 'total' => $logs->total()],
        ]);
    }

    /**
     * Record an activity.
     *
     * `student_id`, `category`, `happened_on`, `title`, `notes`. The wali sees it; the KHS does not.
     */
    public function storeActivity(Request $request, RecordActivity $record): JsonResponse
    {
        $request->validate(['student_id' => ['required', 'integer']]);
        $log = $record->handle(Student::query()->findOrFail($request->integer('student_id')), $request->user(), $request->only(['category', 'happened_on', 'title', 'notes']));

        return response()->json(['data' => ['id' => $log->id]], 201);
    }

    /**
     * Delete an activity.
     *
     * Its recorder, or Admin and Superadmin.
     */
    public function deleteActivity(Request $request, ActivityLog $log): Response
    {
        abort_unless($log->recorded_by === $request->user()->id || in_array(ActiveRole::get(), [Role::Admin, Role::Superadmin], true), 403);
        $log->delete();

        return response()->noContent();
    }
}
