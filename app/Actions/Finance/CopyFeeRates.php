<?php

namespace App\Actions\Finance;

use App\Enums\SemesterTerm;
use App\Models\AcademicYear;
use App\Models\ClassGroup;
use App\Models\FeeRate;
use App\Models\Semester;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Copy one academic year's fee rates into another. A rate for a class
 * follows the level (v4 D20): this year's "Semester I Putra" rate goes to
 * the class that is Semester I Putra next year (the new intake), since
 * classes move up. When no class is at that level next year, the rate is
 * skipped so Finance can add it by hand.
 */
class CopyFeeRates
{
    /**
     * @return array{copied: int, skipped: int}
     */
    public function handle(AcademicYear $from, AcademicYear $to): array
    {
        if ($from->is($to)) {
            throw ValidationException::withMessages(['copyFromYearId' => __('Choose a different year to copy from.')]);
        }

        $fromSemester = $this->firstSemester($from);
        $toSemester = $this->firstSemester($to);
        $classes = ClassGroup::query()->with(['entrySemester.academicYear', 'lastSemester.academicYear'])->get();

        return DB::transaction(function () use ($from, $to, $fromSemester, $toSemester, $classes) {
            $copied = 0;
            $skipped = 0;

            foreach (FeeRate::query()->with('classGroup')->where('academic_year_id', $from->id)->get() as $rate) {
                $classId = $rate->class_group_id === null ? null : $this->classAtSameLevel($rate->classGroup, $classes, $fromSemester, $toSemester)?->id;

                if ($rate->class_group_id !== null && $classId === null) {
                    $skipped++;

                    continue;
                }

                $exists = FeeRate::query()
                    ->where('academic_year_id', $to->id)
                    ->where('fee_type_id', $rate->fee_type_id)
                    ->where('class_group_id', $classId)
                    ->where('entry_year', $rate->entry_year)
                    ->exists();

                if (! $exists) {
                    FeeRate::create([
                        ...$rate->only(['fee_type_id', 'entry_year', 'amount']),
                        'class_group_id' => $classId,
                        'academic_year_id' => $to->id,
                    ]);
                    $copied++;
                }
            }

            return ['copied' => $copied, 'skipped' => $skipped];
        });
    }

    /**
     * The class of the same study program and Putra/Putri that has, in $to,
     * the level $source had in $from.
     *
     * @param  Collection<int, ClassGroup>  $classes
     */
    private function classAtSameLevel(?ClassGroup $source, Collection $classes, ?Semester $from, ?Semester $to): ?ClassGroup
    {
        if ($source === null || $from === null || $to === null) {
            return null;
        }

        $level = $source->levelIn($from);

        return $classes->first(fn (ClassGroup $class) => $class->study_program_id === $source->study_program_id
            && $class->gender === $source->gender
            && $class->usableIn($to)
            && $class->levelIn($to) === $level);
    }

    /**
     * The odd semester of the year (fee rates are yearly), or its only one.
     */
    private function firstSemester(AcademicYear $year): ?Semester
    {
        return Semester::query()->with('academicYear')->where('academic_year_id', $year->id)->get()
            ->sortBy(fn (Semester $semester) => $semester->term === SemesterTerm::Odd ? 0 : 1)
            ->first();
    }
}
