<?php

namespace App\Actions\Academic;

use App\Enums\SemesterStatus;
use App\Enums\SemesterTerm;
use App\Models\AcademicYear;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SaveAcademicYear
{
    /**
     * Create an academic year with its odd and even semesters (both planned),
     * or update its dates.
     *
     * @param  array<string, mixed>  $input
     */
    public function handle(?AcademicYear $year, array $input): AcademicYear
    {
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'regex:/^\d{4}\/\d{4}$/', Rule::unique('academic_years', 'name')->ignore($year?->id)],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after:starts_on'],
        ], [
            'name.regex' => __('Use the format 2026/2027.'),
        ])->validate();

        [$first, $second] = array_map('intval', explode('/', $data['name']));

        if ($second !== $first + 1) {
            throw ValidationException::withMessages(['name' => __('The second year must follow the first, e.g. 2026/2027.')]);
        }

        return DB::transaction(function () use ($year, $data) {
            if ($year !== null) {
                $year->update($data);

                return $year;
            }

            $year = AcademicYear::create($data);

            foreach (SemesterTerm::cases() as $term) {
                $year->semesters()->create(['term' => $term, 'status' => SemesterStatus::Planned]);
            }

            return $year;
        });
    }
}
