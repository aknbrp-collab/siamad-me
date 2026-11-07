<?php

namespace App\Http\Controllers\Api\V1\Teacher;

use App\Actions\Teaching\AcademicRecord;
use App\Actions\Teaching\ManageMeetings;
use App\Enums\AttendanceStatus;
use App\Http\Controllers\Api\V1\Teacher\Concerns\TeachesOffering;
use App\Http\Controllers\Controller;
use App\Models\ClassSession;
use App\Models\CourseEnrollment;
use App\Models\CourseOffering;
use App\Models\Setting;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Pertemuan dan presensi, with the teaching journal fields (materi, metode,
 * catatan) of each meeting.
 */
#[Group('Muhadhir', weight: 11)]
class MeetingController extends Controller
{
    use TeachesOffering;

    /**
     * Meetings and attendance summary.
     *
     * The meetings with how many were present, and per mahasantri their attendance so far (hadir, izin,
     * sakit, alpa, percent) against the minimum.
     */
    public function index(Request $request, CourseOffering $offering, AcademicRecord $record): JsonResponse
    {
        $this->authorizeOffering($request, $offering);

        $sessions = $offering->sessions()->withCount(['attendances as present_count' => fn ($query) => $query->where('status', AttendanceStatus::Present)])->get();
        $enrollments = $offering->enrollments()->with('student.user')->get()->sortBy('student.user.name')->values();
        $summary = $record->attendance(array_values($enrollments->map(fn (CourseEnrollment $enrollment) => $enrollment->id)->all()));

        return response()->json(['data' => [
            'locked' => $offering->loadMissing('semester')->semester->isLocked(),
            'min_attendance_percent' => (int) Setting::get('attendance.min_percent', 75),
            'meetings' => $sessions->map(fn (ClassSession $session) => [...$this->meeting($session), 'present_count' => (int) $session->getAttribute('present_count')])->values()->all(),
            'mahasantri' => $enrollments->map(fn (CourseEnrollment $enrollment) => [
                'enrollment_id' => $enrollment->id,
                'nim' => $enrollment->student->nim,
                'name' => $enrollment->student->user->name,
                'attendance' => $summary[$enrollment->id] ?? null,
            ])->values()->all(),
        ]]);
    }

    /**
     * Add a meeting.
     *
     * Numbered automatically. Online meetings need `meeting_provider` and an https `meeting_url`.
     */
    public function store(Request $request, CourseOffering $offering, ManageMeetings $meetings): JsonResponse
    {
        $this->authorizeOffering($request, $offering);
        $request->validate($this->rules());

        return response()->json(['data' => $this->meeting($meetings->save($offering, null, $this->input($request)))], 201);
    }

    /**
     * Change a meeting.
     */
    public function update(Request $request, CourseOffering $offering, ClassSession $meeting, ManageMeetings $meetings): JsonResponse
    {
        $this->authorizeMeeting($request, $offering, $meeting);
        $request->validate($this->rules());

        return response()->json(['data' => $this->meeting($meetings->save($offering, $meeting, $this->input($request)))]);
    }

    /**
     * Delete a meeting.
     *
     * Only while no attendance was taken.
     */
    public function destroy(Request $request, CourseOffering $offering, ClassSession $meeting, ManageMeetings $meetings): Response
    {
        $this->authorizeMeeting($request, $offering, $meeting);
        $meetings->delete($meeting);

        return response()->noContent();
    }

    /**
     * Attendance sheet.
     *
     * Every mahasantri of the class with the status recorded (or `hadir` as the starting value) and a note.
     */
    public function attendance(Request $request, CourseOffering $offering, ClassSession $meeting): JsonResponse
    {
        $this->authorizeMeeting($request, $offering, $meeting);
        $existing = $meeting->attendances()->get()->keyBy('course_enrollment_id');

        return response()->json(['data' => [
            'meeting' => $this->meeting($meeting),
            'statuses' => array_map(fn (AttendanceStatus $status) => ['value' => $status->value, 'label' => $status->label()], AttendanceStatus::cases()),
            'mahasantri' => $offering->enrollments()->with('student.user')->get()->sortBy('student.user.name')->values()
                ->map(fn (CourseEnrollment $enrollment) => [
                    'enrollment_id' => $enrollment->id,
                    'nim' => $enrollment->student->nim,
                    'name' => $enrollment->student->user->name,
                    'status' => $existing->get($enrollment->id)?->status->value ?? AttendanceStatus::Present->value,
                    'note' => $existing->get($enrollment->id)?->note,
                ])->all(),
        ]]);
    }

    /**
     * Save attendance.
     *
     * `statuses`: {enrollment id: hadir|izin|sakit|alpa} for every mahasantri; `notes` optional.
     */
    public function saveAttendance(Request $request, CourseOffering $offering, ClassSession $meeting, ManageMeetings $meetings): Response
    {
        $this->authorizeMeeting($request, $offering, $meeting);
        $request->validate(['statuses' => ['required', 'array'], 'notes' => ['nullable', 'array']]);

        $meetings->recordAttendance($meeting, (array) $request->input('statuses'), (array) $request->input('notes', []));

        return response()->noContent();
    }

    private function authorizeMeeting(Request $request, CourseOffering $offering, ClassSession $meeting): void
    {
        $this->authorizeOffering($request, $offering);
        abort_unless($meeting->course_offering_id === $offering->id, 404);
    }

    /**
     * @return array<string, list<string>>
     */
    private function rules(): array
    {
        return [
            'held_on' => ['required', 'date'],
            'topic' => ['nullable', 'string', 'max:255'],
            /** offline | online */
            'mode' => ['required', 'string'],
            'meeting_provider' => ['nullable', 'string'],
            'meeting_url' => ['nullable', 'string'],
            /** Jurnal mengajar: the material covered. */
            'material' => ['nullable', 'string', 'max:2000'],
            'method' => ['nullable', 'string', 'max:255'],
            'journal_note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function input(Request $request): array
    {
        return array_map(fn ($value) => $value === '' ? null : $value, $request->only(array_keys($this->rules())));
    }

    /**
     * @return array<string, mixed>
     */
    private function meeting(ClassSession $session): array
    {
        return [
            'id' => $session->id,
            'meeting_no' => $session->meeting_no,
            'held_on' => $session->held_on->toDateString(),
            'topic' => $session->topic,
            'mode' => $session->mode->value,
            'meeting_provider' => $session->meeting_provider?->value,
            'meeting_url' => $session->meeting_url,
            'material' => $session->material,
            'method' => $session->method,
            'journal_note' => $session->journal_note,
            'attendance_taken_at' => $session->attendance_taken_at?->toIso8601String(),
        ];
    }
}
