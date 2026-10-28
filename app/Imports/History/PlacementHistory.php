<?php

namespace App\Imports\History;

use App\Models\ClassEnrollment;
use App\Models\ClassGroup;
use App\Models\Semester;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Str;
use LogicException;

/**
 * Which class each mahasantri was in, in a past semester. Mahasantri of any
 * status (graduated, left) can be placed; the class must suit their gender.
 * A mahasantri already placed in that semester is reported, not moved.
 */
class PlacementHistory implements HistoryImporter
{
    /** @var array<string, list<ClassGroup>>|null classes by lower-case name */
    private ?array $classes = null;

    public function needsSemester(): bool
    {
        return true;
    }

    public function label(): string
    {
        return __('Class placements');
    }

    public function columns(): array
    {
        return [
            'nim' => __('Required. The mahasantri must already exist in SIAKAD.'),
            'class' => __('Required. Class name exactly as on the Classes page, e.g. Semester I Putra.'),
        ];
    }

    public function example(): array
    {
        return ['nim' => '2025110001', 'class' => ClassGroup::query()->orderBy('level')->value('name') ?? 'Semester I Putra'];
    }

    public function key(array $row): string
    {
        return Str::lower(trim($row['nim'] ?? ''));
    }

    public function check(?Semester $semester, array $row): array
    {
        if ($semester === null) {
            throw new LogicException('A semester is required.');
        }

        $errors = [];
        $nim = trim($row['nim'] ?? '');
        $className = trim($row['class'] ?? '');

        $student = $nim === '' ? null : Student::query()->with('user')->where('nim', $nim)->first();
        $matches = $this->classes($semester)[Str::lower($className)] ?? [];
        $class = count($matches) === 1 ? $matches[0] : null;

        if ($student === null) {
            $errors[] = $nim === '' ? __('NIM is required.') : __('No mahasantri with NIM :nim.', ['nim' => $nim]);
        }

        if ($className === '') {
            $errors[] = __('Class is required.');
        } elseif ($class === null) {
            $errors[] = count($matches) > 1
                ? __('More than one class is named ":class".', ['class' => $className])
                : __('No class named ":class".', ['class' => $className]);
        }

        if ($student !== null && $class !== null) {
            if ($class->gender !== null && $class->gender !== $student->gender) {
                $errors[] = __(':class is a :gender class.', ['class' => $class->name, 'gender' => $class->gender->classLabel()]);
            }

            $placed = ClassEnrollment::query()->where('semester_id', $semester->id)->where('student_id', $student->id)->first();

            if ($placed !== null) {
                $errors[] = $placed->class_group_id === $class->id
                    ? __('Already placed in this class.')
                    : __('Already placed in :class this semester.', ['class' => $placed->class_name]);
            }
        }

        return ['input' => ['student_id' => $student?->id, 'class_group_id' => $class?->id, 'class_name' => $class?->nameIn($semester)], 'errors' => $errors];
    }

    public function import(?Semester $semester, array $input, User $by): void
    {
        if ($semester === null) {
            throw new LogicException('A semester is required.');
        }

        ClassEnrollment::create([
            'semester_id' => $semester->id,
            'student_id' => $input['student_id'],
            'class_group_id' => $input['class_group_id'],
            'class_name' => $input['class_name'],
        ]);
    }

    /**
     * Classes by their name in that semester (classes move up, v4 D20).
     *
     * @return array<string, list<ClassGroup>>
     */
    private function classes(Semester $semester): array
    {
        if ($this->classes === null) {
            $this->classes = [];

            foreach (ClassGroup::query()->with(['entrySemester.academicYear', 'lastSemester.academicYear'])->get() as $class) {
                $this->classes[Str::lower($class->nameIn($semester))][] = $class;
            }
        }

        return $this->classes;
    }
}
