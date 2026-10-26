<?php

namespace App\Actions\Teaching;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\CourseEnrollment;
use App\Models\Semester;
use App\Models\Student;
use Illuminate\Support\Collection;

/**
 * KHS (per semester), transcript (all semesters) and attendance summaries.
 * Only finalised grades count toward IP / IPK; the letter and point stored
 * at finalisation are used, never recomputed.
 */
class AcademicRecord
{
    /**
     * @return array{rows: Collection<int, array{enrollment_id: int, code: string, name: string, credits: int, score: string|null, letter: string|null, point: string|null, final: bool}>, credits: int, quality: float, ip: float|null}
     */
    public function khs(Student $student, Semester $semester): array
    {
        $enrollments = $student->courseEnrollments()
            ->with('courseOffering')
            ->whereHas('courseOffering', fn ($query) => $query->where('semester_id', $semester->id))
            ->get()
            ->sortBy('courseOffering.subject_code')
            ->values();

        return $this->summarise($enrollments);
    }

    /**
     * Finalised grades of every semester, grouped by semester.
     *
     * @return array{semesters: Collection<int, array{semester: Semester, rows: Collection<int, array{enrollment_id: int, code: string, name: string, credits: int, score: string|null, letter: string|null, point: string|null, final: bool}>, credits: int, ip: float|null}>, credits: int, ipk: float|null}
     */
    public function transcript(Student $student): array
    {
        $enrollments = $student->courseEnrollments()
            ->with('courseOffering.semester.academicYear')
            ->whereNotNull('finalized_at')
            ->get();

        $semesters = $enrollments
            ->groupBy('courseOffering.semester_id')
            ->map(function (Collection $group) {
                $summary = $this->summarise($group->sortBy('courseOffering.subject_code')->values());

                return [
                    'semester' => $group->first()->courseOffering->semester,
                    'rows' => $summary['rows'],
                    'credits' => $summary['credits'],
                    'ip' => $summary['ip'],
                ];
            })
            // Chronological: academic year, then odd before even.
            ->sortBy(fn (array $entry) => $entry['semester']->academicYear->name.'-'.($entry['semester']->term->value === 'odd' ? 1 : 2))
            ->values();

        $all = $this->summarise($enrollments);

        return ['semesters' => $semesters, 'credits' => $all['credits'], 'ipk' => $all['ip']];
    }

    /**
     * Attendance per enrolment: counts per status and the share of "hadir".
     *
     * @param  list<int>  $enrollmentIds
     * @return array<int, array{hadir: int, izin: int, sakit: int, alpa: int, total: int, percent: int|null}>
     */
    public function attendance(array $enrollmentIds): array
    {
        /** @var array<int, array<string, int>> $counts enrollment id => status => count */
        $counts = [];

        Attendance::query()
            ->whereIn('course_enrollment_id', $enrollmentIds)
            ->selectRaw('course_enrollment_id, status, count(*) as aggregate')
            ->groupBy('course_enrollment_id', 'status')
            ->toBase()
            ->get()
            ->each(function ($row) use (&$counts) {
                $counts[(int) $row->course_enrollment_id][(string) $row->status] = (int) $row->aggregate;
            });

        $summary = [];

        foreach ($enrollmentIds as $id) {
            $present = $counts[$id][AttendanceStatus::Present->value] ?? 0;
            $excused = $counts[$id][AttendanceStatus::Excused->value] ?? 0;
            $sick = $counts[$id][AttendanceStatus::Sick->value] ?? 0;
            $absent = $counts[$id][AttendanceStatus::Absent->value] ?? 0;
            $total = $present + $excused + $sick + $absent;

            $summary[$id] = [
                'hadir' => $present,
                'izin' => $excused,
                'sakit' => $sick,
                'alpa' => $absent,
                'total' => $total,
                'percent' => $total > 0 ? (int) round($present / $total * 100) : null,
            ];
        }

        return $summary;
    }

    /**
     * @param  Collection<int, CourseEnrollment>  $enrollments
     * @return array{rows: Collection<int, array{enrollment_id: int, code: string, name: string, credits: int, score: string|null, letter: string|null, point: string|null, final: bool}>, credits: int, quality: float, ip: float|null}
     */
    private function summarise(Collection $enrollments): array
    {
        $rows = $enrollments->map(fn (CourseEnrollment $enrollment) => [
            'enrollment_id' => $enrollment->id,
            'code' => $enrollment->courseOffering->subject_code,
            'name' => $enrollment->courseOffering->subject_name,
            'credits' => $enrollment->courseOffering->credits,
            'score' => $enrollment->final_score,
            'letter' => $enrollment->final_letter,
            'point' => $enrollment->final_point,
            'final' => $enrollment->finalized_at !== null,
        ]);

        $credits = 0;
        $quality = 0.0;

        foreach ($rows->where('final', true) as $row) {
            $credits += $row['credits'];
            $quality += $row['credits'] * (float) $row['point'];
        }

        return [
            'rows' => $rows,
            'credits' => $credits,
            'quality' => $quality,
            'ip' => $credits > 0 ? round($quality / $credits, 2) : null,
        ];
    }
}
