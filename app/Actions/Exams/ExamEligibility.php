<?php

namespace App\Actions\Exams;

use App\Actions\Teaching\AcademicRecord;
use App\Enums\BillStatus;
use App\Models\Bill;
use App\Models\CourseEnrollment;
use App\Models\ExamDispensation;
use App\Models\ExamPeriod;
use App\Models\ExamSeat;
use App\Models\ExamSession;
use App\Models\Setting;
use App\Models\Student;

/**
 * Whether a mahasantri may print their exam card (v2 D2): the semester's bill
 * is fully paid (lunas), and only subjects where attendance reached the
 * minimum appear on the card. A dispensation (rector's letter) lifts both.
 */
class ExamEligibility
{
    public function __construct(private readonly AcademicRecord $record) {}

    /**
     * @return array{
     *     payment_ok: bool,
     *     outstanding: int,
     *     dispensation: ExamDispensation|null,
     *     subjects: list<array{session: ExamSession, attendance: int|null, ok: bool, seat: ExamSeat|null}>,
     *     eligible: bool
     * }
     */
    public function check(ExamPeriod $period, Student $student): array
    {
        $bill = Bill::query()
            ->where('student_id', $student->id)
            ->where('semester_id', $period->semester_id)
            ->where('status', '!=', BillStatus::Void)
            ->first();

        // No bill for the semester: nothing to pay.
        $paymentOk = $bill === null || $bill->status === BillStatus::Paid;

        $dispensation = ExamDispensation::query()
            ->where('exam_period_id', $period->id)
            ->where('student_id', $student->id)
            ->first();

        $enrollments = CourseEnrollment::query()
            ->where('student_id', $student->id)
            ->whereIn('course_offering_id', $period->sessions()->select('course_offering_id'))
            ->get()
            ->keyBy('course_offering_id');

        $attendance = $this->record->attendance(array_values($enrollments->pluck('id')->all()));
        $minimum = (int) Setting::get('attendance.min_percent', 75);

        $sessions = $period->sessions()
            ->with(['courseOffering', 'rooms'])
            ->whereIn('course_offering_id', $enrollments->keys())
            ->orderBy('held_on')->orderBy('starts_at')
            ->get();

        $seats = ExamSeat::query()
            ->with('room')
            ->whereIn('exam_session_id', $sessions->modelKeys())
            ->whereIn('course_enrollment_id', $enrollments->pluck('id'))
            ->get()
            ->keyBy('exam_session_id');

        $subjects = array_values($sessions->map(function (ExamSession $session) use ($enrollments, $attendance, $minimum, $dispensation, $seats) {
            $percent = $attendance[$enrollments[$session->course_offering_id]->id]['percent'] ?? null;

            return [
                'session' => $session,
                'attendance' => $percent,
                // No meetings recorded yet counts as meeting the minimum.
                'ok' => $dispensation !== null || $percent === null || $percent >= $minimum,
                'seat' => $seats->get($session->id),
            ];
        })->all());

        return [
            'payment_ok' => $paymentOk,
            'outstanding' => $bill === null ? 0 : $bill->outstanding(),
            'dispensation' => $dispensation,
            'subjects' => $subjects,
            'eligible' => ($paymentOk || $dispensation !== null) && collect($subjects)->contains('ok', true),
        ];
    }
}
