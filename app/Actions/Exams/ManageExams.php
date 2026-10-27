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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Exam periods (UTS/UAS), the exam of each course offering (date, time,
 * invigilator), the period's rooms and seat numbers, and dispensations.
 * Every mahasantri gets one room and seat for the whole period (v3 D7).
 * Schedule, rooms and seats change only while the period is unpublished.
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
     * The rooms the period's mahasantri sit in, putra and putri. Other rooms
     * mean other seats: the seats are cleared.
     *
     * @param  list<int|string>  $roomIds
     */
    public function setRooms(ExamPeriod $period, array $roomIds): void
    {
        $period->assertEditable();

        Validator::make(['room_ids' => $roomIds], [
            'room_ids' => ['array'],
            'room_ids.*' => ['integer', 'distinct', Rule::exists('rooms', 'id')->where('is_active', true)],
        ])->validate();

        $ids = array_map('intval', $roomIds);
        sort($ids);

        if ($period->rooms()->pluck('rooms.id')->sort()->values()->all() === $ids) {
            return;
        }

        DB::transaction(function () use ($period, $ids) {
            $period->rooms()->sync($ids);
            $this->clearSeats($period);
        });
    }

    /**
     * @param  array<string, mixed>  $input  course_offering_id, held_on, starts_at, ends_at, invigilator_id
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
        ], [
            'course_offering_id.unique' => __('This subject already has an exam in this period.'),
            'held_on.after_or_equal' => __('The exam date must be within the exam period.'),
            'held_on.before_or_equal' => __('The exam date must be within the exam period.'),
        ])->validate();

        $this->ensureNoClash($session, $data);

        return DB::transaction(function () use ($period, $session, $data) {
            $attributes = [
                'course_offering_id' => (int) $data['course_offering_id'],
                'held_on' => $data['held_on'],
                'starts_at' => $data['starts_at'],
                'ends_at' => $data['ends_at'],
                'invigilator_id' => $data['invigilator_id'] ?? null,
            ];

            if ($session === null) {
                $session = $period->sessions()->create($attributes);
                // New subject, maybe new mahasantri: shuffle again.
                $this->clearSeats($period);
            } else {
                $session->update($attributes);

                if ($session->wasChanged('course_offering_id')) {
                    $this->clearSeats($period);
                }
            }

            return $session;
        });
    }

    public function deleteSession(ExamSession $session): void
    {
        $period = $session->loadMissing('period')->period;
        $period->assertEditable();

        DB::transaction(function () use ($session, $period) {
            $session->delete();
            $this->clearSeats($period);
        });
    }

    /**
     * Mahasantri taking at least one exam of the period.
     *
     * @return Builder<Student>
     */
    public function participants(ExamPeriod $period): Builder
    {
        return Student::query()->whereIn('id', CourseEnrollment::query()
            ->whereIn('course_offering_id', $period->sessions()->select('course_offering_id'))
            ->select('student_id'));
    }

    /**
     * Shuffle every participant of the period into the period's rooms once:
     * putra only in putra rooms, putri only in putri rooms, by room name, up
     * to each room's capacity. Seat numbers start at 1 in every room. The
     * same room and seat hold for every exam of the period.
     */
    public function assignSeats(ExamPeriod $period): int
    {
        $period->assertEditable();

        $students = $this->participants($period)->get();

        if ($students->isEmpty()) {
            throw ValidationException::withMessages(['exam' => __('Schedule exams first: nobody takes an exam in this period yet.')]);
        }

        $rooms = $period->rooms()->get();
        $plan = [];

        foreach ($students->groupBy(fn (Student $student) => $student->gender->value) as $gender => $group) {
            $gender = Gender::from((string) $gender);
            $genderRooms = $rooms->where('gender', $gender)->sortBy('name')->values();
            $capacity = (int) $genderRooms->sum('capacity');

            if ($group->count() > $capacity) {
                throw ValidationException::withMessages(['exam' => __(':count :gender mahasantri take exams in this period, but its :gender rooms seat only :capacity.', [
                    'count' => $group->count(),
                    'gender' => $gender->classLabel(),
                    'capacity' => $capacity,
                ])]);
            }

            $queue = $group->shuffle()->values();
            $index = 0;

            /** @var Room $room */
            foreach ($genderRooms as $room) {
                for ($seat = 1; $seat <= $room->capacity && $index < $queue->count(); $seat++, $index++) {
                    $plan[] = ['student_id' => $queue[$index]->id, 'room_id' => $room->id, 'seat_number' => $seat];
                }
            }
        }

        DB::transaction(function () use ($period, $plan) {
            $period->seats()->delete();

            foreach ($plan as $seat) {
                $period->seats()->create($seat);
            }

            $period->update(['seats_assigned_at' => now()]);
        });

        return count($plan);
    }

    /**
     * Publishing fixes the schedule and seats and lets mahasantri print their
     * exam card. The seats must be assigned after the last schedule change.
     */
    public function publish(ExamPeriod $period, bool $publish = true): void
    {
        $period->loadMissing('semester')->semester->assertOpen();

        if (! $publish) {
            $period->update(['published_at' => null]);

            return;
        }

        $sessions = $period->sessions()->get();

        if ($sessions->isEmpty()) {
            throw ValidationException::withMessages(['exam' => __('Schedule at least one exam before publishing.')]);
        }

        if ($period->seats_assigned_at === null) {
            throw ValidationException::withMessages(['exam' => __('Shuffle the seats first.')]);
        }

        $period->update(['published_at' => now()]);

        $students = $this->participants($period)->with('user')->get()->map(fn (Student $student) => $student->user);

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

    private function clearSeats(ExamPeriod $period): void
    {
        $period->seats()->delete();
        $period->update(['seats_assigned_at' => null]);
    }

    /**
     * No two exams at overlapping times on the same date may share a class or
     * an invigilator. Rooms are shared on purpose: mahasantri keep their
     * period seat, so several subjects are written in one room at once.
     *
     * @param  array<string, mixed>  $data
     */
    private function ensureNoClash(?ExamSession $session, array $data): void
    {
        $classId = (int) CourseOffering::query()->whereKey($data['course_offering_id'])->value('class_group_id');
        $invigilator = isset($data['invigilator_id']) ? (int) $data['invigilator_id'] : null;

        /** @var Collection<int, ExamSession> $others */
        $others = ExamSession::query()
            ->with(['courseOffering.classGroup'])
            ->whereDate('held_on', $data['held_on'])
            ->when($session, fn ($query) => $query->whereKeyNot($session->id))
            ->get()
            ->filter(fn (ExamSession $other) => substr($other->starts_at, 0, 5) < $data['ends_at'] && $data['starts_at'] < substr($other->ends_at, 0, 5));

        $clashes = $others->map(function (ExamSession $other) use ($classId, $invigilator) {
            $shared = collect([
                $other->courseOffering->class_group_id === $classId ? __('same class') : null,
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
