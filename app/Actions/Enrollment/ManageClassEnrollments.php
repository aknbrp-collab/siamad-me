<?php

namespace App\Actions\Enrollment;

use App\Enums\StudentStatus;
use App\Models\ClassEnrollment;
use App\Models\ClassGroup;
use App\Models\Semester;
use App\Models\Student;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManageClassEnrollments
{
    public function __construct(private readonly SyncOfferingEnrollments $sync) {}

    /**
     * Put active students in a class for the semester. A student already in
     * another class that semester is moved.
     *
     * @param  list<int>  $studentIds
     * @return int Number of students enrolled or moved.
     */
    public function enroll(Semester $semester, ClassGroup $classGroup, array $studentIds): int
    {
        $semester->assertOpen();

        return DB::transaction(function () use ($semester, $classGroup, $studentIds) {
            $students = Student::query()
                ->whereKey($studentIds)
                ->where('status', StudentStatus::Active)
                ->get();

            $affectedClasses = [$classGroup->id];

            foreach ($students as $student) {
                $enrollment = ClassEnrollment::query()->firstOrNew([
                    'semester_id' => $semester->id,
                    'student_id' => $student->id,
                ]);

                if ($enrollment->exists && $enrollment->class_group_id !== $classGroup->id) {
                    $this->ensureNoGradesInClass($enrollment);
                    $affectedClasses[] = $enrollment->class_group_id;
                }

                $enrollment->fill(['class_group_id' => $classGroup->id, 'class_name' => $classGroup->name])->save();
            }

            foreach (array_unique($affectedClasses) as $classGroupId) {
                $this->sync->forClass($semester->id, $classGroupId);
            }

            return $students->count();
        });
    }

    public function remove(ClassEnrollment $enrollment): void
    {
        $enrollment->loadMissing('semester')->semester->assertOpen();

        DB::transaction(function () use ($enrollment) {
            $this->ensureNoGradesInClass($enrollment);

            $enrollment->delete();

            $this->sync->forClass($enrollment->semester_id, $enrollment->class_group_id);
        });
    }

    /**
     * Copy rosters from an earlier semester. $classMap maps each source class
     * id to a target class id (null skips that class). Only active students
     * not yet placed in the target semester are copied.
     *
     * @param  array<int|string, int|string|null>  $classMap
     * @return int Number of students placed.
     */
    public function copy(Semester $from, Semester $to, array $classMap): int
    {
        $to->assertOpen();

        if ($from->is($to)) {
            throw ValidationException::withMessages(['copyFrom' => __('Choose a different semester to copy from.')]);
        }

        return DB::transaction(function () use ($from, $to, $classMap) {
            $targets = ClassGroup::query()->whereKey(array_filter($classMap))->get()->keyBy('id');
            $alreadyPlaced = ClassEnrollment::query()->where('semester_id', $to->id)->pluck('student_id');

            $placed = 0;

            $sources = ClassEnrollment::query()
                ->where('semester_id', $from->id)
                ->whereNotIn('student_id', $alreadyPlaced)
                ->whereHas('student', fn ($query) => $query->where('status', StudentStatus::Active))
                ->get();

            foreach ($sources as $source) {
                $target = $targets->get((int) ($classMap[$source->class_group_id] ?? 0));

                if ($target === null) {
                    continue;
                }

                ClassEnrollment::create([
                    'semester_id' => $to->id,
                    'class_group_id' => $target->id,
                    'student_id' => $source->student_id,
                    'class_name' => $target->name,
                ]);

                $placed++;
            }

            foreach ($targets as $target) {
                $this->sync->forClass($to->id, $target->id);
            }

            return $placed;
        });
    }

    private function ensureNoGradesInClass(ClassEnrollment $enrollment): void
    {
        $graded = $enrollment->loadMissing('student.user')->student->courseEnrollments()
            ->whereHas('courseOffering', fn ($query) => $query
                ->where('semester_id', $enrollment->semester_id)
                ->where('class_group_id', $enrollment->class_group_id))
            ->withRecords()
            ->exists();

        if ($graded) {
            throw ValidationException::withMessages([
                'enrollment' => __(':name already has scores or attendance in this class and cannot be moved or removed.', ['name' => $enrollment->student->user->name]),
            ]);
        }
    }
}
