<?php

namespace App\Http\Controllers\Api\V1\Teacher;

use App\Actions\Teaching\AcademicRecord;
use App\Http\Controllers\Api\V1\Teacher\Concerns\TeachesOffering;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\OfferingResource;
use App\Models\ClassEnrollment;
use App\Models\CourseEnrollment;
use App\Models\CourseOffering;
use App\Models\Semester;
use App\Models\Supervision;
use App\Support\ActiveRole;
use App\Support\ApiFiles;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Muhadhir', weight: 11)]
class HomeController extends Controller
{
    use TeachesOffering;

    /**
     * My classes kuliah.
     *
     * The muhadhir's classes of a semester (`?semester=`, default the active one) in timetable order, with
     * the number of mahasantri, meetings and grade components, and whether the grades are final. Also the
     * semesters to choose from.
     */
    public function classes(Request $request): JsonResponse
    {
        $request->validate(['semester' => ['nullable', 'integer']]);
        $teacher = $this->teacher($request);

        $semesters = Semester::query()->with('academicYear')
            ->whereHas('courseOfferings', fn ($query) => $query->where('teacher_id', $teacher->id))
            ->orWhere('status', 'active')
            ->orderByDesc('academic_year_id')->orderByDesc('term')->get();
        $semesterId = $request->integer('semester') ?: (Semester::active()->id ?? $semesters->first()?->id);

        $offerings = CourseOffering::query()
            ->withCount(['enrollments', 'sessions', 'components'])
            ->where('teacher_id', $teacher->id)
            ->where('semester_id', $semesterId)
            ->orderByRaw('day_of_week is null')->orderBy('day_of_week')->orderBy('starts_at')
            ->get();

        return response()->json(['data' => [
            'semesters' => $semesters->map(fn (Semester $semester) => ['id' => $semester->id, 'label' => $semester->label()])->values()->all(),
            'semester_id' => $semesterId,
            'classes' => $offerings->map(fn (CourseOffering $offering) => [
                ...(new OfferingResource($offering))->resolve($request),
                'mahasantri_count' => $offering->enrollments_count,
                'meetings_count' => $offering->sessions_count,
                'components_count' => $offering->components_count,
                'grades_final' => $offering->isFinalized(),
                /** Taught together with other classes (one RPS, one timetable block). */
                'joint' => $offering->joined_to_id !== null,
            ])->values()->all(),
        ]]);
    }

    /**
     * My supervisees.
     *
     * Mahasantri this muhadhir supervises now (pembimbing akademik), with their class, attendance this
     * semester, IPK and credits. Their KHS and transcript: `/students/{id}/khs`, `/transcript`, `/activities`.
     */
    public function supervisees(Request $request, AcademicRecord $record): JsonResponse
    {
        $teacher = $this->teacher($request);
        $supervisions = Supervision::query()->current()->with('student.user')->where('teacher_id', $teacher->id)->get()->sortBy('student.user.name')->values();
        $studentIds = $supervisions->pluck('student_id')->all();
        $semester = Semester::active();

        $classes = $semester === null ? collect() : ClassEnrollment::query()->where('semester_id', $semester->id)->whereIn('student_id', $studentIds)->pluck('class_name', 'student_id');
        $enrollments = $semester === null ? collect() : CourseEnrollment::query()->whereIn('student_id', $studentIds)
            ->whereHas('courseOffering', fn ($query) => $query->where('semester_id', $semester->id))->get(['id', 'student_id']);
        $attendance = $record->attendance(array_values($enrollments->pluck('id')->all()));

        return response()->json(['data' => $supervisions->map(function (Supervision $supervision) use ($classes, $enrollments, $attendance, $record, $request) {
            $mine = $enrollments->where('student_id', $supervision->student_id)->pluck('id');
            $present = $mine->sum(fn (int $id) => $attendance[$id]['hadir'] ?? 0);
            $total = $mine->sum(fn (int $id) => $attendance[$id]['total'] ?? 0);
            $transcript = $record->transcript($supervision->student);
            $student = $supervision->student;

            return [
                'id' => $student->id,
                'nim' => $student->nim,
                'name' => $student->user->name,
                'class_name' => $classes[$student->id] ?? null,
                'attendance_percent' => $total > 0 ? (int) round($present / $total * 100) : null,
                'ipk' => $transcript['ipk'],
                'credits' => $transcript['credits'],
                'photo_url' => $student->photo_path === null ? null : ApiFiles::url($request->user(), ActiveRole::get(), 'student-photo', $student->id),
            ];
        })->values()->all()]);
    }
}
