<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Academic\ManageClassGroups;
use App\Actions\Academic\ManageReferenceData;
use App\Actions\Academic\SaveAcademicYear;
use App\Actions\Academic\SemesterLifecycle;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\ClassGroup;
use App\Models\GradeScale;
use App\Models\Room;
use App\Models\Semester;
use App\Models\StudyProgram;
use App\Models\Subject;
use App\Support\ActiveRole;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The academic structure (Admin, Superadmin): academic years and the
 * semester lifecycle, study programs, subjects, rooms, classes and the grade
 * scale.
 */
#[Group('Admin akademik', weight: 15)]
class AcademicController extends Controller
{
    /**
     * Academic years and semesters.
     *
     * Newest first, each semester with its status and how many class placements and classes kuliah it has.
     */
    public function years(): JsonResponse
    {
        $years = AcademicYear::query()->with(['semesters' => fn ($query) => $query->withCount(['classEnrollments', 'courseOfferings'])])->orderByDesc('name')->get();

        return response()->json(['data' => $years->map(fn (AcademicYear $year) => [
            'id' => $year->id,
            'name' => $year->name,
            'starts_on' => $year->starts_on->toDateString(),
            'ends_on' => $year->ends_on->toDateString(),
            'semesters' => $year->semesters->map(fn (Semester $semester) => [
                ...$this->semester($semester),
                'placements_count' => (int) $semester->getAttribute('class_enrollments_count'),
                'classes_count' => (int) $semester->getAttribute('course_offerings_count'),
            ])->values()->all(),
        ])->values()->all()]);
    }

    /**
     * Add an academic year.
     *
     * `name` as 2026/2027; both semesters are created (planned).
     */
    public function storeYear(Request $request, SaveAcademicYear $save): JsonResponse
    {
        $request->validate(['name' => ['required', 'string'], 'starts_on' => ['required', 'date'], 'ends_on' => ['required', 'date']]);
        $year = $save->handle(null, $request->only(['name', 'starts_on', 'ends_on']));

        return response()->json(['data' => ['id' => $year->id, 'name' => $year->name]], 201);
    }

    /**
     * Change an academic year.
     */
    public function updateYear(Request $request, AcademicYear $year, SaveAcademicYear $save): Response
    {
        $request->validate(['name' => ['required', 'string'], 'starts_on' => ['required', 'date'], 'ends_on' => ['required', 'date']]);
        $save->handle($year, $request->only(['name', 'starts_on', 'ends_on']));

        return response()->noContent();
    }

    /**
     * Activate a semester.
     *
     * The active one becomes the current semester everywhere; the previous active one must be closed first.
     */
    public function activate(Semester $semester, SemesterLifecycle $lifecycle): JsonResponse
    {
        $lifecycle->activate($semester);

        return response()->json(['data' => $this->semester($semester->refresh())]);
    }

    /**
     * Close a semester.
     *
     * Its records become read-only. Without `confirmed: true` it only reports how many classes kuliah still
     * have grades that are not final (`unfinalized`).
     */
    public function close(Request $request, Semester $semester, SemesterLifecycle $lifecycle): JsonResponse
    {
        $request->validate(['confirmed' => ['nullable', 'boolean']]);

        if (! $request->boolean('confirmed')) {
            return response()->json(['data' => ['unfinalized' => $lifecycle->unfinalizedCount($semester), 'closed' => false]]);
        }

        $lifecycle->close($semester, confirmed: true);

        return response()->json(['data' => ['unfinalized' => 0, 'closed' => true]]);
    }

    /**
     * Reopen a closed semester (Superadmin).
     *
     * For a correction, with a `reason`; close it again afterwards.
     */
    public function reopen(Request $request, Semester $semester, SemesterLifecycle $lifecycle): JsonResponse
    {
        abort_unless(ActiveRole::get() === Role::Superadmin, 403);
        $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $lifecycle->reopen($semester, (string) $request->input('reason'));

        return response()->json(['data' => $this->semester($semester->refresh())]);
    }

    /**
     * Study programs.
     */
    public function programs(): JsonResponse
    {
        return response()->json(['data' => StudyProgram::query()->orderBy('code')->get(['id', 'code', 'name'])->toArray()]);
    }

    /**
     * Add or change a study program.
     *
     * Without `id`: a new one.
     */
    public function saveProgram(Request $request, ManageReferenceData $manage): JsonResponse
    {
        $request->validate(['id' => ['nullable', 'integer'], 'code' => ['required', 'string'], 'name' => ['required', 'string']]);
        $program = $manage->saveProgram($request->filled('id') ? StudyProgram::query()->findOrFail($request->integer('id')) : null, $request->only(['code', 'name']));

        return response()->json(['data' => $program->only(['id', 'code', 'name'])]);
    }

    /**
     * Remove a study program.
     *
     * Only while no class, subject or mahasantri uses it.
     */
    public function deleteProgram(StudyProgram $program, ManageReferenceData $manage): Response
    {
        $manage->deleteProgram($program);

        return response()->noContent();
    }

    /**
     * Subjects.
     *
     * `search` on code or name; `active=1` for active ones only.
     */
    public function subjects(Request $request): JsonResponse
    {
        $request->validate(['search' => ['nullable', 'string'], 'active' => ['nullable', 'boolean']]);
        $term = '%'.mb_strtolower(trim((string) $request->input('search'))).'%';

        $subjects = Subject::query()->with('studyProgram')
            ->when($request->filled('search'), fn ($query) => $query->where(fn ($query) => $query->whereRaw('lower(code) like ?', [$term])->orWhereRaw('lower(name) like ?', [$term])))
            ->when($request->boolean('active'), fn ($query) => $query->where('is_active', true))
            ->orderBy('code')->get();

        return response()->json(['data' => $subjects->map(fn (Subject $subject) => [
            ...$subject->only(['id', 'study_program_id', 'code', 'name', 'kitab', 'credits', 'is_active']),
            'study_program' => $subject->studyProgram->code,
        ])->values()->all()]);
    }

    /**
     * Add or change a subject.
     *
     * Classes kuliah already made keep their copy of the code, name and credits.
     */
    public function saveSubject(Request $request, ManageReferenceData $manage): JsonResponse
    {
        $request->validate(['id' => ['nullable', 'integer'], 'study_program_id' => ['required', 'integer'], 'code' => ['required', 'string'], 'name' => ['required', 'string'], 'kitab' => ['nullable', 'string'], 'credits' => ['required', 'integer'], 'is_active' => ['nullable', 'boolean']]);
        $subject = $manage->saveSubject($request->filled('id') ? Subject::query()->findOrFail($request->integer('id')) : null, [
            ...$request->only(['study_program_id', 'code', 'name', 'kitab', 'credits']),
            'is_active' => $request->boolean('is_active', true),
        ]);

        return response()->json(['data' => $subject->only(['id', 'study_program_id', 'code', 'name', 'kitab', 'credits', 'is_active'])]);
    }

    /**
     * Rooms (for exams).
     */
    public function rooms(): JsonResponse
    {
        return response()->json(['data' => Room::query()->orderBy('name')->get()->map(fn (Room $room) => [
            ...$room->only(['id', 'name', 'capacity', 'is_active']),
            'gender' => $room->gender->value,
        ])->values()->all()]);
    }

    /**
     * Add or change a room.
     *
     * `gender`: male (putra) or female (putri).
     */
    public function saveRoom(Request $request, ManageReferenceData $manage): JsonResponse
    {
        $request->validate(['id' => ['nullable', 'integer'], 'name' => ['required', 'string'], 'capacity' => ['required', 'integer'], 'gender' => ['required', 'string'], 'is_active' => ['nullable', 'boolean']]);
        $room = $manage->saveRoom($request->filled('id') ? Room::query()->findOrFail($request->integer('id')) : null, [
            ...$request->only(['name', 'capacity', 'gender']),
            'is_active' => $request->boolean('is_active', true),
        ]);

        return response()->json(['data' => [...$room->only(['id', 'name', 'capacity', 'is_active']), 'gender' => $room->gender->value]]);
    }

    /**
     * Classes.
     *
     * Every class with its level and name this semester (`?semester=`, default the active one), its gender
     * and the semesters it runs; removed classes are left out.
     */
    public function classes(Request $request): JsonResponse
    {
        $request->validate(['semester' => ['nullable', 'integer']]);
        $semester = $request->filled('semester') ? Semester::query()->findOrFail($request->integer('semester')) : Semester::active();

        $classes = ClassGroup::query()->with(['studyProgram', 'entrySemester.academicYear', 'lastSemester.academicYear'])->get()
            ->map(fn (ClassGroup $class) => $semester === null ? $class : $class->presentIn($semester))
            ->sortBy(fn (ClassGroup $class) => sprintf('%02d %s', $class->level, $class->name))->values();

        return response()->json(['data' => $classes->map(fn (ClassGroup $class) => [
            'id' => $class->id,
            'name' => $class->name,
            'label' => $class->label,
            'level' => $class->level,
            'gender' => $class->gender?->value,
            'study_program' => $class->studyProgram->code,
            'entry_semester' => $class->entrySemester?->label(),
            'start_level' => $class->start_level,
            'usable' => $semester === null || $class->usableIn($semester),
        ])->values()->all()]);
    }

    /**
     * Add or change a class.
     *
     * A cohort class: `label` (e.g. "Putra A"), `gender`, the `entry_semester_id` and its `start_level` there;
     * it moves up one level each semester.
     */
    public function saveClass(Request $request, ManageClassGroups $manage): JsonResponse
    {
        $request->validate(['id' => ['nullable', 'integer'], 'study_program_id' => ['required', 'integer'], 'gender' => ['required', 'string'], 'label' => ['nullable', 'string'], 'name' => ['nullable', 'string'], 'entry_semester_id' => ['nullable', 'integer'], 'start_level' => ['nullable', 'integer']]);
        $class = $manage->save(
            $request->filled('id') ? ClassGroup::query()->findOrFail($request->integer('id')) : null,
            $request->only(['study_program_id', 'gender', 'label', 'name', 'entry_semester_id', 'start_level']),
        );

        return response()->json(['data' => ['id' => $class->id, 'name' => $class->name, 'level' => $class->level]]);
    }

    /**
     * Remove a class.
     *
     * Not while it has mahasantri in the active semester; past placements keep it.
     */
    public function deleteClass(ClassGroup $class, ManageReferenceData $manage): Response
    {
        $manage->deleteClass($class);

        return response()->noContent();
    }

    /**
     * Grade scale.
     *
     * Letter, minimum score and grade point, highest first.
     */
    public function gradeScale(): JsonResponse
    {
        return response()->json(['data' => GradeScale::ordered()->map(fn (GradeScale $band) => $band->only(['letter', 'min_score', 'point']))->values()->all()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function semester(Semester $semester): array
    {
        $semester->loadMissing('academicYear');

        return [
            'id' => $semester->id,
            'label' => $semester->label(),
            'term' => $semester->term->value,
            /** planned | active | closed */
            'status' => $semester->status->value,
            'status_note' => $semester->status_note,
        ];
    }
}
