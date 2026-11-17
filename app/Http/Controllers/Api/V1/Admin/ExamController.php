<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Exams\ExamEligibility;
use App\Actions\Exams\ManageExams;
use App\Http\Controllers\Api\V1\DispensationController;
use App\Http\Controllers\Controller;
use App\Models\CourseEnrollment;
use App\Models\DispensationRequest;
use App\Models\ExamDispensation;
use App\Models\ExamPeriod;
use App\Models\ExamSession;
use App\Models\Room;
use App\Models\Semester;
use App\Models\Student;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Exams (Admin, Superadmin): periods (UTS, UAS) per semester, the schedule
 * per class kuliah, rooms and seats, publishing the exam cards, and
 * dispensations for mahasantri with unpaid bills.
 */
#[Group('Admin akademik', weight: 15)]
class ExamController extends Controller
{
    /**
     * Exam periods of a semester.
     */
    public function periods(Semester $semester): JsonResponse
    {
        return response()->json(['data' => ExamPeriod::query()->withCount('sessions')->where('semester_id', $semester->id)->orderBy('starts_on')->get()
            ->map(fn (ExamPeriod $period) => [...$this->period($period), 'sessions_count' => (int) $period->getAttribute('sessions_count')])->values()->all()]);
    }

    /**
     * Add an exam period.
     *
     * `type` (uts | uas), `starts_on`, `ends_on`.
     */
    public function storePeriod(Request $request, Semester $semester, ManageExams $exams): JsonResponse
    {
        $request->validate(['type' => ['required', 'string'], 'starts_on' => ['required', 'date'], 'ends_on' => ['required', 'date']]);

        return response()->json(['data' => $this->period($exams->savePeriod($semester, null, $request->only(['type', 'starts_on', 'ends_on'])))], 201);
    }

    /**
     * An exam period.
     *
     * The schedule (with the rooms its mahasantri sit in), the period's rooms, seats against participants per
     * gender, and the classes kuliah still without an exam.
     */
    public function show(ExamPeriod $period, ManageExams $exams): JsonResponse
    {
        $period->loadMissing('semester');
        $sessions = $period->sessions()->with(['courseOffering', 'invigilator.user'])->orderBy('held_on')->orderBy('starts_at')->get();

        $roomByStudent = $period->seats()->with('room')->get()->mapWithKeys(fn ($seat) => [$seat->student_id => $seat->room->name]);
        $students = CourseEnrollment::query()->whereIn('course_offering_id', $sessions->pluck('course_offering_id'))->get(['course_offering_id', 'student_id'])->groupBy('course_offering_id');
        $participants = $exams->participants($period)->get()->countBy(fn (Student $student) => $student->gender->value);
        $periodRooms = $period->rooms()->get();

        return response()->json(['data' => [
            ...$this->period($period),
            'editable' => ! $period->isPublished() && ! $period->semester->isReadOnly(),
            'sessions' => $sessions->map(fn (ExamSession $session) => [
                'id' => $session->id,
                'class_id' => $session->course_offering_id,
                'subject_name' => $session->courseOffering->subject_name,
                'class_name' => $session->courseOffering->class_name,
                'held_on' => $session->held_on->toDateString(),
                'starts_at' => substr($session->starts_at, 0, 5),
                'ends_at' => substr($session->ends_at, 0, 5),
                'invigilator_id' => $session->invigilator_id,
                'invigilator' => $session->invigilator?->user->name,
                'rooms' => collect($students->get($session->course_offering_id, []))->map(fn ($enrollment) => $roomByStudent->get($enrollment->student_id))->filter()->unique()->sort()->values()->all(),
            ])->values()->all(),
            'room_ids' => $periodRooms->modelKeys(),
            'capacity' => [
                'male' => ['students' => (int) $participants->get('male', 0), 'seats' => (int) $periodRooms->where('gender.value', 'male')->sum('capacity')],
                'female' => ['students' => (int) $participants->get('female', 0), 'seats' => (int) $periodRooms->where('gender.value', 'female')->sum('capacity')],
            ],
            'unscheduled_class_ids' => $period->semester->courseOfferings()->whereNotIn('id', $sessions->pluck('course_offering_id'))->pluck('id')->all(),
        ]]);
    }

    /**
     * Schedule an exam.
     *
     * `course_offering_id`, `held_on`, `starts_at`, `ends_at`, `invigilator_id` (a muhadhir). Clashes for a
     * class or invigilator are refused.
     */
    public function saveSession(Request $request, ExamPeriod $period, ManageExams $exams): JsonResponse
    {
        $request->validate(['id' => ['nullable', 'integer'], 'course_offering_id' => ['required', 'integer'], 'held_on' => ['required', 'date'], 'starts_at' => ['required', 'string'], 'ends_at' => ['required', 'string'], 'invigilator_id' => ['nullable', 'integer']]);
        $session = $request->filled('id') ? $period->sessions()->findOrFail($request->integer('id')) : null;

        $saved = $exams->saveSession($period, $session, [
            ...$request->only(['course_offering_id', 'held_on', 'starts_at', 'ends_at']),
            'invigilator_id' => $request->integer('invigilator_id') ?: null,
        ]);

        return response()->json(['data' => ['id' => $saved->id]], $session === null ? 201 : 200);
    }

    /**
     * Remove an exam from the schedule.
     */
    public function deleteSession(ExamPeriod $period, ExamSession $session, ManageExams $exams): Response
    {
        abort_unless($session->exam_period_id === $period->id, 404);
        $exams->deleteSession($session);

        return response()->noContent();
    }

    /**
     * Set the period's rooms.
     *
     * `room_ids`: the rooms used, putra and putri.
     */
    public function rooms(Request $request, ExamPeriod $period, ManageExams $exams): Response
    {
        $request->validate(['room_ids' => ['present', 'array'], 'room_ids.*' => ['integer']]);
        $exams->setRooms($period, array_values(array_map('intval', (array) $request->input('room_ids'))));

        return response()->noContent();
    }

    /**
     * Assign seats.
     *
     * Places every participant in a room of their gender. Returns how many were seated.
     */
    public function assignSeats(ExamPeriod $period, ManageExams $exams): JsonResponse
    {
        return response()->json(['data' => ['seated' => $exams->assignSeats($period)]]);
    }

    /**
     * Publish or unpublish.
     *
     * Published, mahasantri see their schedule and (when eligible) print the exam card.
     */
    public function publish(Request $request, ExamPeriod $period, ManageExams $exams): JsonResponse
    {
        $request->validate(['published' => ['required', 'boolean']]);
        $exams->publish($period, $request->boolean('published'));

        return response()->json(['data' => $this->period($period->refresh())]);
    }

    /**
     * Participants and their card status.
     *
     * Mahasantri with at least one exam in the period (`search` on NIM or name), paginated, each with
     * eligibility: bills, dispensation, seat and attendance per subject.
     */
    public function participants(Request $request, ExamPeriod $period, ExamEligibility $eligibility): JsonResponse
    {
        $request->validate(['search' => ['nullable', 'string']]);
        $term = '%'.mb_strtolower(trim((string) $request->input('search'))).'%';

        $students = Student::query()->with('user')
            ->whereIn('id', CourseEnrollment::query()->whereIn('course_offering_id', $period->sessions()->select('course_offering_id'))->select('student_id'))
            ->when($request->filled('search'), fn ($query) => $query->where(fn ($query) => $query->whereRaw('lower(nim) like ?', [$term])
                ->orWhereHas('user', fn ($query) => $query->whereRaw('lower(name) like ?', [$term]))))
            ->orderBy('nim')->paginate(25);

        $requests = DispensationRequest::query()->where('semester_id', $period->semester_id)
            ->whereIn('student_id', collect($students->items())->map(fn (Student $student) => $student->id))
            ->get()->keyBy('student_id');

        return response()->json([
            'data' => collect($students->items())->map(function (Student $student) use ($eligibility, $period, $requests, $request) {
                $asked = $requests->get($student->id);
                $status = $eligibility->check($period, $student);
                $status['seat']?->loadMissing('room');

                return [
                    'student_id' => $student->id,
                    'nim' => $student->nim,
                    'name' => $student->user->name,
                    'eligible' => $status['eligible'],
                    'payment_ok' => $status['payment_ok'],
                    'outstanding' => $status['outstanding'],
                    'dispensation' => $status['dispensation']?->only(['letter_number', 'note']),
                    /** The mahasantri's own request (v7 D47), with a signed link to the letter. */
                    'dispensation_request' => $asked === null ? null : [
                        'reason' => $asked->reason,
                        'written_at' => $asked->updated_at->toIso8601String(),
                        'letter_url' => DispensationController::letterUrl($request, $asked),
                    ],
                    'seat' => $status['seat'] === null ? null : ['room' => $status['seat']->room->name, 'number' => $status['seat']->seat_number],
                    'subjects_short' => collect($status['subjects'])->reject(fn (array $subject) => $subject['ok'])->map(fn (array $subject) => $subject['session']->courseOffering->subject_name)->values()->all(),
                ];
            })->values()->all(),
            'meta' => ['current_page' => $students->currentPage(), 'last_page' => $students->lastPage(), 'total' => $students->total()],
        ]);
    }

    /**
     * Grant a dispensation.
     *
     * Lets a mahasantri with unpaid bills sit the exams: `letter_number`, `letter_date`, `note`.
     */
    public function grantDispensation(Request $request, ExamPeriod $period, Student $student, ManageExams $exams): Response
    {
        $request->validate(['letter_number' => ['required', 'string'], 'letter_date' => ['required', 'date'], 'note' => ['nullable', 'string']]);
        $exams->grantDispensation($period, $student, $request->only(['letter_number', 'letter_date', 'note']), $request->user());

        return response()->noContent();
    }

    /**
     * Revoke a dispensation.
     */
    public function revokeDispensation(ExamPeriod $period, Student $student, ManageExams $exams): Response
    {
        $exams->revokeDispensation(ExamDispensation::query()->where('exam_period_id', $period->id)->where('student_id', $student->id)->firstOrFail());

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function period(ExamPeriod $period): array
    {
        return [
            'id' => $period->id,
            'label' => $period->label(),
            'type' => $period->type->value,
            'starts_on' => $period->starts_on->toDateString(),
            'ends_on' => $period->ends_on->toDateString(),
            'published' => $period->isPublished(),
            'seats_assigned' => $period->seats_assigned_at !== null,
        ];
    }
}
