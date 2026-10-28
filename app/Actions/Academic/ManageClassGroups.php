<?php

namespace App\Actions\Academic;

use App\Enums\Gender;
use App\Enums\SemesterStatus;
use App\Models\ClassEnrollment;
use App\Models\ClassGroup;
use App\Models\CourseOffering;
use App\Models\Semester;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Classes as cohorts (v4 D20): a new class starts at a level in its entry
 * semester and moves up every semester. Classes made before v4 keep their
 * fixed name and level; only their name and Putra/Putri can change.
 */
class ManageClassGroups
{
    /**
     * @param  array<string, mixed>  $input  study_program_id, label, entry_semester_id, start_level, gender (cohort) or name, gender (older class)
     */
    public function save(?ClassGroup $classGroup, array $input): ClassGroup
    {
        $cohort = $classGroup === null || $classGroup->isCohort();
        $started = $classGroup !== null && ClassEnrollment::query()->where('class_group_id', $classGroup->id)->exists();

        $data = Validator::make($input, [
            'study_program_id' => ['required', 'integer', Rule::exists('study_programs', 'id')->whereNull('deleted_at')],
            'gender' => ['required', Rule::enum(Gender::class)],
            ...($cohort ? [
                'label' => ['required', 'string', 'max:100'],
                'entry_semester_id' => [Rule::requiredIf(! $started), 'nullable', 'integer', Rule::exists('semesters', 'id')->whereNot('status', SemesterStatus::Closed->value)],
                'start_level' => [Rule::requiredIf(! $started), 'nullable', 'integer', 'between:1,'.ClassGroup::MAX_LEVEL],
            ] : [
                'name' => ['required', 'string', 'max:255'],
            ]),
        ], [
            'gender.required' => __('Choose Putra or Putri.'),
        ], [
            'label' => __('name'),
            'entry_semester_id' => __('first semester'),
            'start_level' => __('starting level'),
        ])->validate();

        $this->ensureGenderStillFits($classGroup, $data['gender']);

        return DB::transaction(function () use ($classGroup, $data, $cohort, $started) {
            $classGroup ??= new ClassGroup;

            $attributes = ['study_program_id' => $data['study_program_id'], 'gender' => $data['gender']];

            if ($cohort) {
                $attributes['label'] = trim($data['label']);

                // Where a class starts cannot move once mahasantri were placed in it.
                if (! $started) {
                    $attributes['entry_semester_id'] = (int) $data['entry_semester_id'];
                    $attributes['start_level'] = (int) $data['start_level'];
                    $attributes['level'] = (int) $data['start_level'];
                }

                $attributes['name'] = ClassGroup::cohortName((int) ($attributes['start_level'] ?? $classGroup->start_level), $attributes['label']);
            } else {
                $attributes['name'] = trim($data['name']);
            }

            $classGroup->fill($attributes);
            $this->ensureUnique($classGroup);
            $classGroup->save();

            if ($cohort) {
                $classGroup->load('entrySemester.academicYear');
                $current = Semester::active();
                $classGroup->refreshFor($current ?? $classGroup->entrySemester);
            }

            $this->refreshSnapshots($classGroup);

            return $classGroup;
        });
    }

    /**
     * Make every cohort's name and level those of $semester (the semester
     * being activated). Older classes keep theirs.
     */
    public function moveUp(Semester $semester): void
    {
        ClassGroup::query()->with('entrySemester.academicYear')->whereNotNull('entry_semester_id')->get()
            ->each(fn (ClassGroup $classGroup) => $classGroup->refreshFor($semester));
    }

    /**
     * Semesters that are still open show the class under its new name.
     */
    private function refreshSnapshots(ClassGroup $classGroup): void
    {
        $open = Semester::query()->with('academicYear')->where('status', '!=', SemesterStatus::Closed)->get();
        $classGroup->loadMissing('entrySemester.academicYear');

        foreach ($open as $semester) {
            ClassEnrollment::query()->where('semester_id', $semester->id)->where('class_group_id', $classGroup->id)
                ->update(['class_name' => $classGroup->nameIn($semester), 'level' => $classGroup->levelIn($semester)]);
            CourseOffering::query()->where('semester_id', $semester->id)->where('class_group_id', $classGroup->id)
                ->update(['class_name' => $classGroup->nameIn($semester)]);
        }
    }

    private function ensureUnique(ClassGroup $classGroup): void
    {
        $duplicate = ClassGroup::query()
            ->where('study_program_id', $classGroup->study_program_id)
            ->when($classGroup->isCohort(),
                fn ($query) => $query->where('entry_semester_id', $classGroup->entry_semester_id)->where('start_level', $classGroup->start_level)->where('label', $classGroup->label),
                fn ($query) => $query->whereNull('entry_semester_id')->where('name', $classGroup->name))
            ->when($classGroup->exists, fn ($query) => $query->whereKeyNot($classGroup->id))
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([$classGroup->isCohort() ? 'label' : 'name' => __('A class with this name already exists.')]);
        }
    }

    /**
     * Changing the gender of a class holding the other gender this semester would make it mixed.
     */
    private function ensureGenderStillFits(?ClassGroup $classGroup, string $gender): void
    {
        $active = Semester::active();

        if ($classGroup === null || $active === null) {
            return;
        }

        $mismatch = ClassEnrollment::query()
            ->where('semester_id', $active->id)
            ->where('class_group_id', $classGroup->id)
            ->whereHas('student', fn ($query) => $query->where('gender', '!=', $gender))
            ->exists();

        if ($mismatch) {
            throw ValidationException::withMessages(['gender' => __('This class has mahasantri of the other gender in the active semester.')]);
        }
    }
}
