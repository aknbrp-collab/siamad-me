<?php

namespace App\Actions\Exams;

use App\Enums\ExamType;
use App\Enums\Gender;
use App\Models\CourseEnrollment;
use App\Models\CourseOffering;
use App\Models\ExamDispensation;
use App\Models\ExamPeriod;
use App\Models\ExamSession;
use App\Models\Room;
use App\Models\Semester;
use App\Models\Student;
use App\Models\User;
use App\Support\Notify;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Exam periods (UTS/UAS), the exam of each course offering (date, time,
 * rooms, invigilator), seat numbers and dispensations. Schedule and seats
 * change only while the period is unpublished.
 */
class ManageExams
{
    /**
     * @param  array<string, mixed>  $input  type, starts_on, ends_on
     */
    public function savePeriod(Semester $semester, ?ExamPeriod $period, array $input): ExamPeriod
    {
        $semester->assertOpen();

        $data = Validator::make($input, [
            'type' => ['required', Rule::enum(ExamType::class), Rule::unique('exam_periods')->where('semester_id', $semester->id)->ignore($period?->id)],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
        ], [
            'type.unique' => __('This semester already has this exam.'),
        ])->validate();

        if ($period !== null) {
            $period->assertEditable();
            $period->update($data);

            return $period;
        }

        return ExamPeriod::create([...$data, 'semester_id' => $semester->id]);
    }

    /**
     * @param  array<string, mixed>  $input  course_offering_id, held_on, starts_at, ends_at, invigilator_id, room_ids
     */
    public function saveSession(ExamPeriod $period, ?ExamSession $session, array $input): ExamSession
    {
        $period->assertEditable();

        $data = Validator::make($input, [
            'course_offering_id' => [
                'required', 'integer',
                Rule::exists('course_offerings', 'id')->where('semester_id', $period->semester_id),
                Rule::unique('exam_sessions')->where('exam_period_id', $period->id)->ignore($session?->id),
            ],
            'held_on' => ['required', 'date', 'after_or_equal:'.$period->starts_on->toDateString(), 'before_or_equal:'.$period->ends_on->toDateString()],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i', 'after:starts_at'],
            'invigilator_id' => ['nullable', 'integer', Rule::exists('teachers', 'id')],
            'room_ids' => ['required', 'array', 'min:1'],
            'room_ids.*' => ['integer', 'distinct', Rule::exists('rooms', 'id')->where('is_active', true)],
        ], [
            'course_offering_id.unique' => __('This subject already has an exam in this period.'),
            'held_on.after_or_equal' => __('The exam date must be within the exam period.'),
            'held_on.before_or_equal' => __('The exam date must be within the exam period.'),
            'room_ids.required' => __('Choose at least one room.'),
        ])->validate();

        $roomIds = array_values(array_map('intval', $data['room_ids']));
        $this->ensureNoClash($period, $session, $data, $roomIds);

        return DB::transaction(function () use ($period, $session, $data, $roomIds) {
            $attributes = [
                'course_offering_id' => (int) $data['course_offering_id'],
                'held_on' => $data['held_on'],
                'starts_at' => $data['starts_at'],
                'ends_at' => $data['ends_at'],
                'invigilator_id' => $data['invigilator_id'] ?? null,
            ];

            if ($session === null) {
                $session = $period->sessions()->create($attributes);
            } else {
                $roomsChanged = $session->rooms()->pluck('rooms.id')->sort()->values()->all() !== collect($roomIds)->sort()->values()->all();
                $session->update($attributes);

                // New rooms or another subject: the old seats no longer fit.
                if ($roomsChanged || $session->wasChanged('course_offering_id')) {
                    $this->clearSeats($session);
                }
            }

            $session->rooms()->sync($roomIds);

            return $session;
        });
    }

    public function deleteSession(ExamSession $session): void
    {
        $session->loadMissing('period')->period->assertEditable();
        $session->delete();
    }

    /**
     * Shuffle the session's mahasantri into its rooms: putra only in putra
     * rooms, putri only in putri rooms, up to each room's capacity. Seat
     * numbers start at 1 in every room.
     */
    public function assignSeats(ExamSession $session): int
    {
        $session->loadMissing(['period', 'rooms']);
        $session->period->assertEditable();

        $enrollments = CourseEnrollment::query()
            ->with('student.user')
            ->where('course_offering_id', $session->course_offering_id)
            ->get();

        $plan = [];

        foreach ($enrollments->groupBy(fn (CourseEnrollment $enrollment) => $enrollment->student->gender->value) as $gender => $group) {
            $rooms = $session->rooms->where('gender', Gender::from((string) $gender))->sortBy('name')->values();
            $capacity = (int) $rooms->sum('capacity');

            if ($group->count() > $capacity) {
                throw ValidationException::withMessages(['exam' => __(':subject: :count :gender mahasantri, but :gender rooms seat only :capacity.', [
                    'subject' => $session->loadMissing('courseOffering')->courseOffering->subject_name,
                    'count' => $group->count(),
                    'gender' => Gender::from((string) $gender)->classLabel(),
                    'capacity' => $capacity,
                ])]);
            }

            $queue = $group->shuffle()->values();
            $index = 0;

            foreach ($rooms as $room) {
                for ($seat = 1; $seat <= $room->capacity && $index < $queue->count(); $seat++, $index++) {
                    $plan[] = ['course_enrollment_id' => $queue[$index]->id, 'room_id' => $room->id, 'seat_number' => $seat];
                }
            }
        }

        DB::transaction(function () use ($session, $plan) {
            $session->seats()->delete();

            foreach ($plan as $seat) {
                $session->seats()->create($seat);
            }

            $session->update(['seats_assigned_at' => now()]);
        });

        return count($plan);
    }

    /**
     * Assign seats in every session of the period.
     */
    public function assignAllSeats(ExamPeriod $period): int
    {
        return (int) $period->sessions()->get()->sum(fn (ExamSession $session) => $this->assignSeats($session));
    }

    /**
     * Publishing fixes the schedule and seats and lets mahasantri print their
     * exam card. Every session needs its seats first.
     */
    public function publish(ExamPeriod $period, bool $publish = true): void
    {
        $period->loadMissing('semester')->semester->assertOpen();

        if (! $publish) {
            $period->update(['published_at' => null]);

            return;
        }

        $sessions = $period->sessions()->with('courseOffering')->get();

        if ($sessions->isEmpty()) {
            throw ValidationException::withMessages(['exam' => __('Schedule at least one exam before publishing.')]);
        }

        $unseated = $sessions->whereNull('seats_assigned_at');

        if ($unseated->isNotEmpty()) {
            throw ValidationException::withMessages(['exam' => __('Assign seats first for: :subjects.', [
                'subjects' => $unseated->map(fn (ExamSession $session) => $session->courseOffering->subject_name)->join(', '),
            ])]);
        }

        $period->update(['published_at' => now()]);

        $students = CourseEnrollment::query()
            ->with('student.user')
            ->whereIn('course_offering_id', $sessions->pluck('course_offering_id'))
            ->get()
            ->map(fn (CourseEnrollment $enrollment) => $enrollment->student->user);

        Notify::send(
            $students, 'exam',
            __(':exam schedule and exam card', ['exam' => $period->type->short()]),
            __('The schedule and your seat numbers are out. Print your exam card once the requirements are met.'),
            route('mahasantri.exams'),
            'identification',
        );
    }

    /**
     * Let a mahasantri sit the period without meeting the requirements,
     * on the strength of a letter from the rector.
     *
     * @param  array<string, mixed>  $input  letter_number, letter_date, note
     */
    public function grantDispensation(ExamPeriod $period, Student $student, array $input, User $by): ExamDispensation
    {
        $period->loadMissing('semester')->semester->assertOpen();

        $data = Validator::make($input, [
            'letter_number' => ['required', 'string', 'max:100'],
            'letter_date' => ['required', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:500'],
        ])->validate();

        return ExamDispensation::query()->updateOrCreate(
            ['exam_period_id' => $period->id, 'student_id' => $student->id],
            [...$data, 'granted_by' => $by->id],
        );
    }

    public function revokeDispensation(ExamDispensation $dispensation): void
    {
        $dispensation->loadMissing('period.semester')->period->semester->assertOpen();
        $dispensation->delete();
    }

    private function clearSeats(ExamSession $session): void
    {
        $session->seats()->delete();
        $session->update(['seats_assigned_at' => null]);
    }

    /**
     * No two exams at overlapping times on the same date may share a class,
     * a room or an invigilator (same rule as the weekly schedule).
     *
     * @param  array<string, mixed>  $data
     * @param  list<int>  $roomIds
     */
    private function ensureNoClash(ExamPeriod $period, ?ExamSession $session, array $data, array $roomIds): void
    {
        $classId = (int) CourseOffering::query()->whereKey($data['course_offering_id'])->value('class_group_id');
        $invigilator = isset($data['invigilator_id']) ? (int) $data['invigilator_id'] : null;

        /** @var Collection<int, ExamSession> $others */
        $others = ExamSession::query()
            ->with(['courseOffering.classGroup', 'rooms'])
            ->whereDate('held_on', $data['held_on'])
            ->when($session, fn ($query) => $query->whereKeyNot($session->id))
            ->get()
            ->filter(fn (ExamSession $other) => substr($other->starts_at, 0, 5) < $data['ends_at'] && $data['starts_at'] < substr($other->ends_at, 0, 5));

        $clashes = $others->map(function (ExamSession $other) use ($classId, $invigilator, $roomIds) {
            $shared = collect([
                $other->courseOffering->class_group_id === $classId ? __('same class') : null,
                $other->rooms->pluck('id')->intersect($roomIds)->isNotEmpty() ? __('same room') : null,
                $invigilator !== null && $other->invigilator_id === $invigilator ? __('same invigilator') : null,
            ])->filter();

            return $shared->isEmpty() ? null : $other->courseOffering->subject_name.' ('.$other->courseOffering->classGroup->name.'), '
                .substr($other->starts_at, 0, 5).'–'.substr($other->ends_at, 0, 5).': '.$shared->implode(', ');
        })->filter();

        if ($clashes->isNotEmpty()) {
            throw ValidationException::withMessages(['starts_at' => __('This time clashes with :clashes.', ['clashes' => $clashes->implode('; ')])]);
        }
    }
}
