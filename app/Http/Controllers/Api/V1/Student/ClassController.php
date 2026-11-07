<?php

namespace App\Http\Controllers\Api\V1\Student;

use App\Http\Controllers\Api\V1\Student\Concerns\ResolvesStudent;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\AssignmentResource;
use App\Http\Resources\V1\ClassSessionResource;
use App\Http\Resources\V1\MaterialResource;
use App\Http\Resources\V1\OfferingResource;
use App\Http\Resources\V1\QuizResource;
use App\Models\CourseOffering;
use App\Models\Semester;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Kelas saya: the mahasantri's classes kuliah this semester (also their
 * timetable) and each class with its meetings, materials, assignments and
 * quizzes.
 */
#[Group('Mahasantri', weight: 9)]
class ClassController extends Controller
{
    use ResolvesStudent;

    /**
     * My classes and timetable.
     *
     * This semester's classes in timetable order (day 1 = Monday), with counts of materials, open
     * assignments and open quizzes.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $student = $this->student($request);

        $offerings = CourseOffering::query()
            ->with(['teacher.user'])
            ->withCount([
                'materials',
                'assignments as open_assignments_count' => fn ($query) => $query->where('due_at', '>', now()),
                'quizzes as open_quizzes_count' => fn ($query) => $query->whereNotNull('published_at')->where('opens_at', '<=', now())->where('closes_at', '>', now()),
            ])
            ->where('semester_id', Semester::active()?->id)
            ->whereHas('enrollments', fn ($query) => $query->where('student_id', $student->id))
            ->orderByRaw('day_of_week is null')->orderBy('day_of_week')->orderBy('starts_at')
            ->get();

        return OfferingResource::collection($offerings);
    }

    /**
     * One class.
     *
     * The class with its meetings (and my attendance), materials, assignments (and my submission) and
     * published quizzes (and my attempts).
     */
    public function show(Request $request, CourseOffering $offering): JsonResponse
    {
        $enrollment = $this->enrollment($request, $offering);
        $offering->load('teacher.user');

        $sessions = $offering->sessions()->with(['attendances' => fn ($query) => $query->where('course_enrollment_id', $enrollment->id)])->get();
        $assignments = $offering->assignments()->with(['submissions' => fn ($query) => $query->where('course_enrollment_id', $enrollment->id)])->get();
        $quizzes = $offering->quizzes()->whereNotNull('published_at')
            ->with(['attempts' => fn ($query) => $query->where('course_enrollment_id', $enrollment->id)->orderBy('id')])->get();

        return response()->json(['data' => [
            'class' => (new OfferingResource($offering))->resolve($request),
            'meetings' => ClassSessionResource::collection($sessions)->resolve($request),
            'materials' => MaterialResource::collection($offering->materials()->get())->resolve($request),
            'assignments' => AssignmentResource::collection($assignments)->resolve($request),
            'quizzes' => QuizResource::collection($quizzes)->resolve($request),
        ]]);
    }
}
