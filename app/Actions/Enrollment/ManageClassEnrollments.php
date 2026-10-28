<?php

namespace App\Actions\Enrollment;

use App\Enums\StudentStatus;
use App\Models\ClassEnrollment;
use App\Models\ClassGroup;
use App\Models\Semester;
use App\Models\Student;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManageClassEnrollments
{
    public function __construct(private readonly SyncOfferingEnrollments $sync) {}

    /**
     * Put active students in a class for the semester. A student already in
     * another class that semester is moved. Classes are Putra or Putri: the
     * class must have its gender set and every student must match it.
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
                ->with('user')
                ->get();

            $this->ensureGenderFits($classGroup, $students);
            $this->ensureUsable($classGroup, $semester);

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

                $enrollment->fill([
                    'class_group_id' => $classGroup->id,
                    'class_name' => $classGroup->nameIn($semester),
                    'level' => $classGroup->levelIn($semester),
                ])->save();
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
     * not yet placed in the target semester are copied; a student whose gender
     * does not match the target class is skipped (and counted) so they can be
     * placed by hand.
     *
     * @param  array<int|string, int|string|null>  $classMap
     * @return array{placed: int, skipped: int}
     */
    public function copy(Semester $from, Semester $to, array $classMap): array
    {
        $to->assertOpen();

        if ($from->is($to)) {
            throw ValidationException::withMessages(['copyFrom' => __('Choose a different semester to copy from.')]);
        }

        return DB::transaction(function () use ($from, $to, $classMap) {
            $targets = ClassGroup::query()->whereKey(array_filter($classMap))->get()->keyBy('id');

            if ($unset = $targets->whereNull('gender')->pluck('name')->join(', ')) {
                throw ValidationException::withMessages([
                    'copyFrom' => __('Set Putra or Putri for :classes first (Classes page).', ['classes' => $unset]),
                ]);
            }

            $targets->each(fn (ClassGroup $target) => $this->ensureUsable($target, $to));

            $alreadyPlaced = ClassEnrollment::query()->where('semester_id', $to->id)->pluck('student_id');

            $placed = 0;
            $skipped = 0;

            $sources = ClassEnrollment::query()
                ->with('student')
                ->where('semester_id', $from->id)
                ->whereNotIn('student_id', $alreadyPlaced)
                ->whereHas('student', fn ($query) => $query->where('status', StudentStatus::Active))
                ->get();

            foreach ($sources as $source) {
                $target = $targets->get((int) ($classMap[$source->class_group_id] ?? 0));

                if ($target === null) {
                    continue;
                }

                if ($source->student->gender !== $target->gender) {
                    $skipped++;

                    continue;
                }

                ClassEnrollment::create([
                    'semester_id' => $to->id,
                    'class_group_id' => $target->id,
                    'student_id' => $source->student_id,
                    'class_name' => $target->nameIn($to),
                    'level' => $target->levelIn($to),
                ]);

                $placed++;
            }

            foreach ($targets as $target) {
                $this->sync->forClass($to->id, $target->id);
            }

            return ['placed' => $placed, 'skipped' => $skipped];
        });
    }

    /**
     * The semester of study each student should be in during $semester: one
     * above the level of their class in the latest earlier semester, or
     * Semester I for students never placed before.
     *
     * @param  list<int>  $studentIds
     * @return array<int, int> student id => level
     */
    public function expectedLevels(Semester $semester, array $studentIds): array
    {
        $current = $semester->chronology();

        $latest = ClassEnrollment::query()
            ->with(['semester.academicYear', 'classGroup'])
            ->whereIn('student_id', $studentIds)
            ->get()
            ->filter(fn (ClassEnrollment $enrollment) => $enrollment->semester->chronology() < $current)
            ->sortBy(fn (ClassEnrollment $enrollment) => $enrollment->semester->chronology())
            ->keyBy('student_id');

        $levels = [];

        foreach ($studentIds as $id) {
            $previous = $latest->get($id);
            // The level the student had then (kept on the placement), not the class's level now.
            $levels[$id] = $previous ? min(($previous->level ?? $previous->classGroup->level) + 1, ClassGroup::MAX_LEVEL) : 1;
        }

        return $levels;
    }

    /**
     * A class is used from its first semester until it passes the last level;
     * a class made before v4 until its last semester.
     */
    private function ensureUsable(ClassGroup $classGroup, Semester $semester): void
    {
        if (! $classGroup->usableIn($semester)) {
            throw ValidationException::withMessages([
                'enrollment' => __(':class is not in use in :semester.', ['class' => $classGroup->name, 'semester' => $semester->label()]),
            ]);
        }
    }

    /**
     * @param  Collection<int, Student>  $students
     */
    private function ensureGenderFits(ClassGroup $classGroup, Collection $students): void
    {
        if ($classGroup->gender === null) {
            throw ValidationException::withMessages([
                'enrollment' => __('Set Putra or Putri for :classes first (Classes page).', ['classes' => $classGroup->name]),
            ]);
        }

        $misfits = $students->filter(fn (Student $student) => $student->gender !== $classGroup->gender);

        if ($misfits->isNotEmpty()) {
            throw ValidationException::withMessages([
                'enrollment' => __(':names cannot join :class, a :gender class.', [
                    'names' => $misfits->map(fn (Student $student) => $student->user->name)->join(', '),
                    'class' => $classGroup->name,
                    'gender' => $classGroup->gender->classLabel(),
                ]),
            ]);
        }
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
