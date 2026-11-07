<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Enrollment\ManageClassEnrollments;
use App\Actions\Enrollment\ManageCourseOfferings;
use App\Enums\StudentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\OfferingResource;
use App\Models\ClassEnrollment;
use App\Models\ClassGroup;
use App\Models\CourseOffering;
use App\Models\Semester;
use App\Models\Student;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * One semester's classes (Admin, Superadmin): which mahasantri are in which
 * class (placements) and the classes kuliah with their muhadhir and time.
 */
#[Group('Admin akademik', weight: 15)]
class SemesterController extends Controller
{
    /**
     * Classes and their mahasantri.
     *
     * The classes usable in the semester with how many mahasantri they have; `?class=` also returns that
     * class's roster.
     */
    public function placements(Request $request, Semester $semester): JsonResponse
    {
        $request->validate(['class' => ['nullable', 'integer']]);

        $classes = ClassGroup::withTrashed()->with(['studyProgram'])
            ->withCount(['classEnrollments as students_count' => fn ($query) => $query->where('semester_id', $semester->id)])
            ->get()
            ->filter(fn (ClassGroup $class) => (int) $class->getAttribute('students_count') > 0 || $class->usableIn($semester))
            ->map(fn (ClassGroup $class) => $class->presentIn($semester))
            ->sortBy(fn (ClassGroup $class) => sprintf('%02d %s', $class->level, $class->name))->values();

        $roster = ! $request->filled('class') ? null : ClassEnrollment::query()->with('student.user')
            ->where('semester_id', $semester->id)->where('class_group_id', $request->integer('class'))
            ->get()->sortBy('student.user.name')->values()
            ->map(fn (ClassEnrollment $enrollment) => [
                'placement_id' => $enrollment->id,
                'student_id' => $enrollment->student_id,
                'nim' => $enrollment->student->nim,
                'name' => $enrollment->student->user->name,
            ])->all();

        return response()->json(['data' => [
            'locked' => $semester->isLocked(),
            'classes' => $classes->map(fn (ClassGroup $class) => [
                'id' => $class->id,
                'name' => $class->name,
                'level' => $class->level,
                'gender' => $class->gender?->value,
                'students_count' => (int) $class->getAttribute('students_count'),
            ])->values()->all(),
            'roster' => $roster,
        ]]);
    }

    /**
     * Mahasantri to place.
     *
     * Active mahasantri matching `search` (NIM or name, 2+ characters), of the class's gender when `class`
     * is given, with their class this semester and the semester of study they should be in.
     */
    public function candidates(Request $request, Semester $semester, ManageClassEnrollments $manage): JsonResponse
    {
        $request->validate(['search' => ['required', 'string', 'min:2'], 'class' => ['nullable', 'integer']]);
        $class = $request->filled('class') ? ClassGroup::withTrashed()->findOrFail($request->integer('class')) : null;
        $term = '%'.mb_strtolower(trim((string) $request->input('search'))).'%';

        $students = Student::query()
            ->with(['user', 'classEnrollments' => fn ($query) => $query->where('semester_id', $semester->id)])
            ->where('status', StudentStatus::Active)
            ->when($class?->gender, fn (Builder $query, $gender) => $query->where('gender', $gender))
            ->where(fn (Builder $query) => $query->whereRaw('lower(nim) like ?', [$term])
                ->orWhereHas('user', fn (Builder $query) => $query->whereRaw('lower(name) like ?', [$term])))
            ->orderBy('nim')->limit(15)->get();
        $levels = $manage->expectedLevels($semester, array_values($students->map(fn (Student $student) => $student->id)->all()));

        return response()->json(['data' => $students->map(fn (Student $student) => [
            'id' => $student->id,
            'nim' => $student->nim,
            'name' => $student->user->name,
            'current_class' => $student->classEnrollments->first()?->class_name,
            'expected_level' => $levels[$student->id] ?? null,
        ])->values()->all()]);
    }

    /**
     * Place mahasantri in a class.
     *
     * `student_ids` into `class_id`; a mahasantri already in another class this semester moves. They join the
     * class's classes kuliah.
     */
    public function enroll(Request $request, Semester $semester, ManageClassEnrollments $manage): JsonResponse
    {
        $request->validate(['class_id' => ['required', 'integer'], 'student_ids' => ['required', 'array'], 'student_ids.*' => ['integer']]);

        $placed = $manage->enroll($semester, ClassGroup::withTrashed()->findOrFail($request->integer('class_id')), array_values(array_map('intval', (array) $request->input('student_ids'))));

        return response()->json(['data' => ['placed' => $placed]]);
    }

    /**
     * Remove a placement.
     */
    public function removePlacement(Semester $semester, ClassEnrollment $placement, ManageClassEnrollments $manage): Response
    {
        abort_unless($placement->semester_id === $semester->id, 404);
        $manage->remove($placement);

        return response()->noContent();
    }

    /**
     * Copy placements from another semester.
     *
     * `from_semester_id` and `class_map` {old class id: new class id or null to skip}. Cohort classes keep their
     * mahasantri as they move up.
     */
    public function copyPlacements(Request $request, Semester $semester, ManageClassEnrollments $manage): JsonResponse
    {
        $request->validate(['from_semester_id' => ['required', 'integer'], 'class_map' => ['required', 'array']]);

        return response()->json(['data' => $manage->copy(Semester::query()->findOrFail($request->integer('from_semester_id')), $semester, (array) $request->input('class_map'))]);
    }

    /**
     * Classes kuliah.
     *
     * Subject, class, muhadhir, time and room of every class kuliah of the semester, with the number of
     * mahasantri.
     */
    public function offerings(Request $request, Semester $semester): JsonResponse
    {
        $offerings = CourseOffering::query()->with(['teacher.user'])->withCount('enrollments')
            ->where('semester_id', $semester->id)->orderBy('class_group_id')->orderBy('subject_code')->get();

        return response()->json(['data' => $offerings->map(fn (CourseOffering $offering) => [
            ...(new OfferingResource($offering))->resolve($request),
            'subject_id' => $offering->subject_id,
            'class_group_id' => $offering->class_group_id,
            'teacher_id' => $offering->teacher_id,
            'mahasantri_count' => (int) $offering->getAttribute('enrollments_count'),
            'joined_to_id' => $offering->joined_to_id,
            'grades_final' => $offering->isFinalized(),
        ])->values()->all()]);
    }

    /**
     * Add or change a class kuliah.
     *
     * `subject_id`, `class_group_id`, `teacher_id`, `day_of_week` (1 = Monday), `starts_at`, `ends_at`, `room`.
     * The same subject and muhadhir at the same time in another class gives 422 with `errors.joint_class`:
     * ask whether it is a joint class and send again with `joint: true`.
     */
    public function saveOffering(Request $request, Semester $semester, ManageCourseOfferings $manage): JsonResponse
    {
        $request->validate([
            'id' => ['nullable', 'integer'], 'subject_id' => ['required', 'integer'], 'class_group_id' => ['required', 'integer'], 'teacher_id' => ['required', 'integer'],
            'day_of_week' => ['nullable', 'integer'], 'starts_at' => ['nullable', 'string'], 'ends_at' => ['nullable', 'string'], 'room' => ['nullable', 'string'], 'joint' => ['nullable', 'boolean'],
        ]);
        $offering = $request->filled('id') ? CourseOffering::query()->where('semester_id', $semester->id)->findOrFail($request->integer('id')) : null;

        $saved = $manage->save($semester, $offering, [
            'subject_id' => $request->integer('subject_id'),
            'class_group_id' => $request->integer('class_group_id'),
            'teacher_id' => $request->integer('teacher_id'),
            'day_of_week' => $request->filled('day_of_week') ? $request->integer('day_of_week') : null,
            'starts_at' => $request->input('starts_at') ?: null,
            'ends_at' => $request->input('ends_at') ?: null,
            'room' => $request->input('room') ?: null,
        ], $request->boolean('joint'));

        return response()->json(['data' => ['id' => $saved->id, 'joined_to_id' => $saved->joined_to_id]], $offering === null ? 201 : 200);
    }

    /**
     * Delete a class kuliah.
     *
     * Only while it has no meetings, materials, work or grades.
     */
    public function deleteOffering(Semester $semester, CourseOffering $offering, ManageCourseOfferings $manage): Response
    {
        abort_unless($offering->semester_id === $semester->id, 404);
        $manage->delete($offering);

        return response()->noContent();
    }

    /**
     * Copy classes kuliah from another semester.
     *
     * Skips ones that exist, are no longer possible, or clash in time (`clashes`).
     */
    public function copyOfferings(Request $request, Semester $semester, ManageCourseOfferings $manage): JsonResponse
    {
        $request->validate(['from_semester_id' => ['required', 'integer']]);

        return response()->json(['data' => $manage->copy(Semester::query()->findOrFail($request->integer('from_semester_id')), $semester)]);
    }
}
