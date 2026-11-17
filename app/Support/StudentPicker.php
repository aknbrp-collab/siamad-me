<?php

namespace App\Support;

use App\Enums\Gender;
use App\Enums\StudentStatus;
use App\Models\ClassGroup;
use App\Models\Semester;
use App\Models\Student;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Choosing mahasantri (v7 D43): every picker lists all active mahasantri straight away, narrowed by class,
 * Putra/Putri and a NIM or name search, a page at a time.
 */
final class StudentPicker
{
    /** Mahasantri shown at once; "Muat lagi" adds as many again. */
    public const PAGE = 50;

    /** The class filter value for mahasantri without a class in the semester. */
    public const NO_CLASS = 'none';

    /**
     * Narrow a mahasantri query by the picker's filters.
     *
     * @param  Builder<Student>  $query
     * @param  int|string|null  $class  a class id, NO_CLASS, or empty for every class
     * @param  string|null  $gender  'male' or 'female', or empty for both
     * @return Builder<Student>
     */
    public static function filter(Builder $query, ?string $search = null, int|string|null $class = null, ?string $gender = null, ?Semester $semester = null, bool $activeOnly = true): Builder
    {
        $search = trim((string) $search);
        $term = '%'.mb_strtolower($search).'%';
        $gender = Gender::tryFrom((string) $gender);

        return $query
            ->when($activeOnly, fn (Builder $query) => $query->where('status', StudentStatus::Active))
            ->when($gender, fn (Builder $query, Gender $gender) => $query->where('gender', $gender))
            ->when($semester && $class === self::NO_CLASS, fn (Builder $query) => $query->whereDoesntHave('classEnrollments', fn (Builder $query) => $query->where('semester_id', $semester?->id)))
            ->when($semester && is_numeric($class), fn (Builder $query) => $query->whereHas('classEnrollments', fn (Builder $query) => $query
                ->where('semester_id', $semester?->id)
                ->where('class_group_id', (int) $class)))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereRaw('lower(nim) like ?', [$term])
                ->orWhereHas('user', fn (Builder $query) => $query->whereRaw('lower(name) like ?', [$term]))));
    }

    /**
     * The API's picker parameters: `search` (NIM or name, optional), `class_id` (a class, or `none` for
     * mahasantri without a class in the semester) and `gender` (`male` / `female`).
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'class_id' => ['nullable', Rule::when(fn ($input) => ($input['class_id'] ?? null) !== self::NO_CLASS, ['integer'])],
            'gender' => ['nullable', Rule::enum(Gender::class)],
        ];
    }

    /**
     * Narrow a mahasantri query by the API request's picker parameters.
     *
     * @param  Builder<Student>  $query
     * @return Builder<Student>
     */
    public static function fromRequest(Builder $query, Request $request, ?Semester $semester, bool $activeOnly = true, ?string $gender = null): Builder
    {
        return self::filter(
            $query,
            $request->string('search')->toString(),
            $request->input('class_id'),
            $gender ?? $request->string('gender')->toString(),
            $semester,
            $activeOnly,
        );
    }

    /**
     * A mahasantri query with what every picker shows: the account and the class in the semester.
     *
     * @return Builder<Student>
     */
    public static function query(?Semester $semester): Builder
    {
        return Student::query()->with([
            'user',
            'classEnrollments' => fn ($query) => $query->where('semester_id', $semester->id ?? 0),
        ]);
    }

    /**
     * The classes to filter by, named as in the semester ("Semester III Putra A").
     *
     * @return list<array{id: int, name: string}>
     */
    public static function classes(?Semester $semester, ?string $gender = null): array
    {
        $gender = Gender::tryFrom((string) $gender);

        return array_values(ClassGroup::query()
            ->when($gender, fn (Builder $query, Gender $gender) => $query->where('gender', $gender))
            ->orderBy('level')->orderBy('name')
            ->get()
            ->map(fn (ClassGroup $class) => ['id' => $class->id, 'name' => $semester ? $class->nameIn($semester) : $class->name])
            ->sortBy('name', SORT_NATURAL)
            ->values()
            ->all());
    }
}
