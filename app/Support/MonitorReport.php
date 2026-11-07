<?php

namespace App\Support;

use App\Actions\Teaching\AcademicRecord;
use App\Models\Assignment;
use App\Models\CourseEnrollment;
use App\Models\CourseOffering;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

/**
 * The academic monitor's view of a semester (Pemantauan): every class kuliah
 * with its meetings, attendance against the minimum, missing work and grade
 * state, the mahasantri under the minimum, and the grade spread. Used by the
 * monitor dashboard and the API.
 */
final class MonitorReport
{
    /** @var Collection<int, CourseOffering> */
    public readonly Collection $offerings;

    public readonly int $minimum;

    /** @var array<int, array{hadir: int, izin: int, sakit: int, alpa: int, total: int, percent: int|null}> */
    public readonly array $attendance;

    public function __construct(?int $semesterId)
    {
        $this->minimum = (int) Setting::get('attendance.min_percent', 75);

        $this->offerings = CourseOffering::query()
            ->with(['semester', 'classGroup', 'teacher.user', 'enrollments.student.user'])
            ->withCount(['sessions as meetings_count' => fn ($query) => $query->whereNotNull('attendance_taken_at'), 'components'])
            ->where('semester_id', $semesterId)
            ->orderBy('class_group_id')->orderBy('subject_code')
            ->get();

        $this->attendance = app(AcademicRecord::class)->attendance(
            array_values($this->offerings->flatMap(fn (CourseOffering $offering) => $offering->enrollments->map(fn (CourseEnrollment $enrollment) => $enrollment->id))->all()),
        );
    }

    /**
     * Per offering: average attendance and how many are below the minimum.
     *
     * @return array<int, array{average: int|null, below: int}>
     */
    public function offeringStats(): array
    {
        $stats = [];

        foreach ($this->offerings as $offering) {
            $percents = $offering->enrollments
                ->map(fn (CourseEnrollment $enrollment) => $this->attendance[$enrollment->id]['percent'] ?? null)
                ->filter(fn ($percent) => $percent !== null);

            $stats[$offering->id] = [
                'average' => $percents->isEmpty() ? null : (int) round((float) $percents->avg()),
                'below' => $percents->filter(fn (int $percent) => $percent < $this->minimum)->count(),
            ];
        }

        return $stats;
    }

    /**
     * Submissions still missing for assignments past their due date, per offering.
     *
     * @return array<int, int>
     */
    public function missingWork(): array
    {
        $missing = [];

        $assignments = Assignment::query()
            ->whereIn('course_offering_id', $this->offerings->modelKeys())
            ->where('due_at', '<', now())
            ->withCount('submissions')
            ->get();

        foreach ($assignments as $assignment) {
            $enrolled = $this->offerings->firstWhere('id', $assignment->course_offering_id)?->enrollments->count() ?? 0;
            $missing[$assignment->course_offering_id] = ($missing[$assignment->course_offering_id] ?? 0) + max(0, $enrolled - (int) $assignment->getAttribute('submissions_count'));
        }

        return $missing;
    }

    /**
     * Mahasantri under the attendance minimum, lowest first.
     *
     * @return SupportCollection<int, array{name: string, nim: string, class: string, subject: string, percent: int}>
     */
    public function belowMinimum(): SupportCollection
    {
        return $this->offerings->toBase()
            ->flatMap(fn (CourseOffering $offering) => $offering->enrollments->toBase()->map(fn (CourseEnrollment $enrollment) => [
                'name' => $enrollment->student->user->name,
                'nim' => $enrollment->student->nim,
                'class' => $offering->class_name,
                'subject' => $offering->subject_name,
                'percent' => $this->attendance[$enrollment->id]['percent'] ?? null,
            ]))
            ->filter(fn (array $row) => $row['percent'] !== null && $row['percent'] < $this->minimum)
            ->sortBy('percent')
            ->values();
    }

    /**
     * @return array<string, int> letter => count, over finalised grades
     */
    public function distribution(): array
    {
        return $this->offerings
            ->flatMap(fn (CourseOffering $offering) => $offering->enrollments)
            ->whereNotNull('final_letter')
            ->countBy('final_letter')
            ->sortKeys()
            ->all();
    }
}
