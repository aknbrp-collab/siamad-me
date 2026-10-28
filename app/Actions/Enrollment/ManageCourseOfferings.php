<?php

namespace App\Actions\Enrollment;

use App\Actions\Teaching\ManageTeachingDocuments;
use App\Enums\TeacherStatus;
use App\Models\ClassGroup;
use App\Models\CourseOffering;
use App\Models\Semester;
use App\Models\Subject;
use App\Support\ScheduleClashes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ManageCourseOfferings
{
    public function __construct(
        private readonly SyncOfferingEnrollments $sync,
        private readonly ManageTeachingDocuments $documents,
    ) {}

    /**
     * Create or update an offering. Subject code, name and credits are
     * snapshotted whenever the subject is (re)chosen.
     *
     * A time clash is refused, except with an offering of the same subject and
     * muhadhir in another class (v4 D21): that may be a joint class. Then the
     * caller is asked (error key "joint_class") and, when it confirms with
     * $joint, the offerings are saved and joined for RPS and journal.
     *
     * @param  array<string, mixed>  $input
     */
    public function save(Semester $semester, ?CourseOffering $offering, array $input, bool $joint = false): CourseOffering
    {
        $semester->assertOpen();

        $data = Validator::make($input, [
            'subject_id' => [
                'required', 'integer',
                Rule::exists('subjects', 'id')->whereNull('deleted_at')->where('is_active', true),
                Rule::unique('course_offerings')
                    ->where('semester_id', $semester->id)
                    ->where('class_group_id', $input['class_group_id'] ?? null)
                    ->ignore($offering?->id),
            ],
            'class_group_id' => ['required', 'integer', Rule::exists('class_groups', 'id')->whereNull('deleted_at')],
            'teacher_id' => ['required', 'integer', Rule::exists('teachers', 'id')->where('status', TeacherStatus::Active->value)],
            'day_of_week' => ['nullable', 'integer', 'between:1,7'],
            'starts_at' => ['nullable', 'date_format:H:i', 'required_with:ends_at'],
            'ends_at' => ['nullable', 'date_format:H:i', 'after:starts_at'],
            'room' => ['nullable', 'string', 'max:50'],
        ], [
            'subject_id.unique' => __('This class already has this subject in the semester.'),
        ])->validate();

        $classGroup = ClassGroup::query()->whereKey($data['class_group_id'])->firstOrFail();

        if (! $classGroup->usableIn($semester) && ($offering === null || $offering->class_group_id !== $classGroup->id)) {
            throw ValidationException::withMessages(['class_group_id' => __(':class is not in use in :semester.', ['class' => $classGroup->name, 'semester' => $semester->label()])]);
        }

        // The same muhadhir, class or room cannot be in two places at once,
        // unless it is one lesson taught to several classes together.
        $slot = $this->slot($data);
        $leadId = $offering?->teachingLeadId();
        [$joinable, $hard] = ScheduleClashes::for($semester->id, $slot, $offering?->id)
            ->partition(fn (CourseOffering $other) => ScheduleClashes::joinable($slot, $other));
        $joinable = $joinable->reject(fn (CourseOffering $other) => $other->teachingLeadId() === $leadId)->values();

        if ($hard->isNotEmpty()) {
            throw ValidationException::withMessages([
                'starts_at' => __('This time clashes with :clashes.', ['clashes' => ScheduleClashes::describe($slot, $hard)]),
            ]);
        }

        if ($joinable->isNotEmpty() && ! $joint) {
            throw ValidationException::withMessages([
                'joint_class' => __('This time clashes with :clashes.', ['clashes' => ScheduleClashes::describe($slot, $joinable)]),
            ]);
        }

        return DB::transaction(function () use ($semester, $offering, $data, $joinable, $classGroup) {
            $offering ??= new CourseOffering(['semester_id' => $semester->id]);

            if (! $offering->exists || $offering->subject_id !== (int) $data['subject_id']) {
                $subject = Subject::query()->whereKey($data['subject_id'])->firstOrFail();
                $offering->fill([
                    'subject_code' => $subject->code,
                    'subject_name' => $subject->name,
                    'credits' => $subject->credits,
                ]);
            }

            $classChanged = $offering->exists && $offering->class_group_id !== (int) $data['class_group_id'];

            if ($classChanged && $offering->enrollments()->withRecords()->exists()) {
                throw ValidationException::withMessages(['class_group_id' => __('Scores or attendance exist for this offering; its class can no longer change.')]);
            }

            $offering->fill([...$data, 'class_name' => $classGroup->nameIn($semester)])->save();

            $this->sync->handle($offering);

            if ($joinable->isNotEmpty()) {
                $this->documents->join([$offering->id, ...$joinable->modelKeys()]);
            }

            return $offering->refresh();
        });
    }

    public function delete(CourseOffering $offering): void
    {
        $offering->loadMissing('semester')->semester->assertOpen();

        if ($offering->enrollments()->withRecords()->exists()) {
            throw ValidationException::withMessages(['offering' => __('Scores or attendance exist for this offering; it cannot be deleted.')]);
        }

        DB::transaction(function () use ($offering) {
            $offering->enrollments()->get()->each->delete();
            $offering->delete();
        });
    }

    /**
     * Copy offerings (subject × class × teacher × schedule) from another
     * semester, skipping ones that already exist, whose subject, class or
     * teacher is no longer available, or whose time clashes with an offering
     * already in the target semester (counted separately).
     *
     * @return array{copied: int, skipped: int, clashes: int}
     */
    public function copy(Semester $from, Semester $to): array
    {
        $to->assertOpen();

        if ($from->is($to)) {
            throw ValidationException::withMessages(['copyOfferingsFrom' => __('Choose a different semester to copy from.')]);
        }

        return DB::transaction(function () use ($from, $to) {
            $copied = 0;
            $skipped = 0;
            $clashes = 0;

            $sources = CourseOffering::query()
                ->with(['subject', 'classGroup', 'teacher'])
                ->where('semester_id', $from->id)
                ->get();

            foreach ($sources as $source) {
                // Classes move up (v4 D20): a class at another level now is assigned its subjects by hand.
                $usable = $source->subject->is_active && ! $source->subject->trashed()
                    && $source->classGroup->usableIn($to)
                    && $source->classGroup->levelIn($to) === $source->classGroup->levelIn($from)
                    && $source->teacher->status === TeacherStatus::Active;

                $exists = CourseOffering::query()
                    ->where('semester_id', $to->id)
                    ->where('subject_id', $source->subject_id)
                    ->where('class_group_id', $source->class_group_id)
                    ->exists();

                if (! $usable || $exists) {
                    $skipped++;

                    continue;
                }

                $slot = $this->slot($source->only(['subject_id', 'day_of_week', 'starts_at', 'ends_at', 'teacher_id', 'class_group_id', 'room']));
                $clashing = ScheduleClashes::for($to->id, $slot);

                // A class taught together last semester stays joined with its copied partners.
                $wasJoint = $source->joined_to_id !== null || CourseOffering::query()->where('joined_to_id', $source->id)->exists();
                $rejoin = $wasJoint && $clashing->isNotEmpty() && $clashing->every(fn (CourseOffering $other) => ScheduleClashes::joinable($slot, $other));

                if ($clashing->isNotEmpty() && ! $rejoin) {
                    $clashes++;

                    continue;
                }

                $offering = CourseOffering::create([
                    ...$source->only(['subject_id', 'class_group_id', 'teacher_id', 'day_of_week', 'starts_at', 'ends_at', 'room']),
                    'semester_id' => $to->id,
                    'class_name' => $source->classGroup->nameIn($to),
                    'subject_code' => $source->subject->code,
                    'subject_name' => $source->subject->name,
                    'credits' => $source->subject->credits,
                ]);

                $this->sync->handle($offering);

                if ($rejoin) {
                    $this->documents->join([$offering->id, ...$clashing->modelKeys()]);
                }

                $copied++;
            }

            return ['copied' => $copied, 'skipped' => $skipped, 'clashes' => $clashes];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{day_of_week: int|null, starts_at: string|null, ends_at: string|null, teacher_id: int, class_group_id: int, room: string|null, subject_id: int|null}
     */
    private function slot(array $data): array
    {
        return [
            'subject_id' => isset($data['subject_id']) ? (int) $data['subject_id'] : null,
            'day_of_week' => isset($data['day_of_week']) ? (int) $data['day_of_week'] : null,
            'starts_at' => isset($data['starts_at']) ? (string) $data['starts_at'] : null,
            'ends_at' => isset($data['ends_at']) ? (string) $data['ends_at'] : null,
            'teacher_id' => (int) $data['teacher_id'],
            'class_group_id' => (int) $data['class_group_id'],
            'room' => isset($data['room']) ? (string) $data['room'] : null,
        ];
    }
}
