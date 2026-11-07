<?php

namespace App\Http\Controllers\Api\V1\Student;

use App\Actions\Evaluations\ManageEvaluations;
use App\Enums\DocumentStatus;
use App\Http\Controllers\Api\V1\Student\Concerns\ResolvesStudent;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\OfferingResource;
use App\Models\Assignment;
use App\Models\Bill;
use App\Models\CourseOffering;
use App\Models\Quiz;
use App\Models\Semester;
use App\Support\ActiveRole;
use App\Support\ApiFiles;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

#[Group('Mahasantri', weight: 9)]
class HomeController extends Controller
{
    use ResolvesStudent;

    /**
     * Dashboard.
     *
     * What the home screen shows: today's classes, assignments due in the next 7 days that are not
     * submitted, open quizzes not taken, the amount still owed, classes to evaluate, documents to upload and
     * unread notifications.
     */
    public function dashboard(Request $request, ManageEvaluations $evaluations): JsonResponse
    {
        $student = $this->student($request);
        $semester = Semester::active();

        $offerings = $semester === null ? new Collection : CourseOffering::query()->with('teacher.user')
            ->where('semester_id', $semester->id)
            ->whereHas('enrollments', fn ($query) => $query->where('student_id', $student->id))
            ->get();
        $ids = $offerings->modelKeys();

        $assignments = Assignment::query()->with('courseOffering')
            ->whereIn('course_offering_id', $ids)
            ->whereBetween('due_at', [now(), now()->addDays(7)])
            ->whereDoesntHave('submissions', fn ($query) => $query->whereHas('courseEnrollment', fn ($query) => $query->where('student_id', $student->id)))
            ->orderBy('due_at')->limit(10)->get();

        $quizzes = Quiz::query()->whereIn('course_offering_id', $ids)
            ->whereNotNull('published_at')->where('opens_at', '<=', now())->where('closes_at', '>', now())
            ->whereDoesntHave('attempts', fn ($query) => $query->whereHas('courseEnrollment', fn ($query) => $query->where('student_id', $student->id)))
            ->count();

        return response()->json(['data' => [
            'semester' => $semester?->label(),
            'today' => OfferingResource::collection($offerings->where('day_of_week', now()->dayOfWeekIso)->sortBy('starts_at')->values())->resolve($request),
            'assignments_due' => $assignments->map(fn (Assignment $assignment) => [
                'id' => $assignment->id,
                'class_id' => $assignment->course_offering_id,
                'subject_name' => $assignment->courseOffering->subject_name,
                'title' => $assignment->title,
                'due_at' => $assignment->due_at->toIso8601String(),
            ])->values()->all(),
            'open_quizzes' => $quizzes,
            'outstanding' => (int) Bill::query()->where('student_id', $student->id)->whereIn('status', ['unpaid', 'partial'])->sum(DB::raw('net_amount - paid_amount')),
            'evaluations_pending' => $evaluations->pendingCount($student),
            'documents_to_upload' => $student->documents()->whereIn('status', [DocumentStatus::Missing, DocumentStatus::Rejected])->count(),
            'unread_notifications' => $request->user()->unreadNotifications()->count(),
        ]]);
    }

    /**
     * Academic supervisor.
     *
     * The mahasantri's pembimbing akademik (null when none is assigned).
     */
    public function supervision(Request $request): JsonResponse
    {
        $supervision = $this->student($request)->currentSupervision()->with('teacher.user')->first();
        $teacher = $supervision?->teacher;

        return response()->json(['data' => $teacher === null ? null : [
            'teacher' => [
                'name' => $teacher->user->name,
                'title' => $teacher->title,
                'phone' => $teacher->phone,
                'email' => $teacher->user->email,
                'photo_url' => $teacher->photo_path === null ? null : ApiFiles::url($request->user(), ActiveRole::get(), 'teacher-photo', $teacher->id),
            ],
            'since' => $supervision->started_on->toDateString(),
        ]]);
    }
}
