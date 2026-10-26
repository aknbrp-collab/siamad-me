<?php

namespace App\Actions\Enrollment;

use App\Enums\TeacherStatus;
use App\Models\CourseOffering;
use App\Models\Semester;
use App\Models\Subject;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ManageCourseOfferings
{
    public function __construct(private readonly SyncOfferingEnrollments $sync) {}

    /**
     * Create or update an offering. Subject code, name and credits are
     * snapshotted whenever the subject is (re)chosen.
     *
     * @param  array<string, mixed>  $input
     */
    public function save(Semester $semester, ?CourseOffering $offering, array $input): CourseOffering
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

        return DB::transaction(function () use ($semester, $offering, $data) {
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

            $offering->fill($data)->save();

            $this->sync->handle($offering);

            return $offering;
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
     * semester, skipping ones that already exist or whose subject, class or
     * teacher is no longer available.
     *
     * @return array{copied: int, skipped: int}
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

            $sources = CourseOffering::query()
                ->with(['subject', 'classGroup', 'teacher'])
                ->where('semester_id', $from->id)
                ->get();

            foreach ($sources as $source) {
                $usable = $source->subject->is_active && ! $source->subject->trashed()
                    && ! $source->classGroup->trashed()
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

                $offering = CourseOffering::create([
                    ...$source->only(['subject_id', 'class_group_id', 'teacher_id', 'day_of_week', 'starts_at', 'ends_at', 'room']),
                    'semester_id' => $to->id,
                    'subject_code' => $source->subject->code,
                    'subject_name' => $source->subject->name,
                    'credits' => $source->subject->credits,
                ]);

                $this->sync->handle($offering);
                $copied++;
            }

            return ['copied' => $copied, 'skipped' => $skipped];
        });
    }
}
