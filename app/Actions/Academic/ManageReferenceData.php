<?php

namespace App\Actions\Academic;

use App\Enums\Gender;
use App\Models\ClassEnrollment;
use App\Models\ClassGroup;
use App\Models\Room;
use App\Models\Semester;
use App\Models\StudyProgram;
use App\Models\Subject;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The academic office's reference data: study programs, subjects and rooms,
 * and removing a class. Used by the Admin pages and the API (v6 D37).
 */
class ManageReferenceData
{
    /**
     * @return array<string, list<mixed>>
     */
    public static function programRules(?int $ignoreId = null): array
    {
        return [
            'code' => ['required', 'string', 'max:20', 'alpha_dash', Rule::unique('study_programs', 'code')->ignore($ignoreId)],
            'name' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, list<mixed>>
     */
    public static function roomRules(?int $ignoreId = null): array
    {
        return [
            'name' => ['required', 'string', 'max:50', Rule::unique('rooms', 'name')->ignore($ignoreId)],
            'capacity' => ['required', 'integer', 'between:1,1000'],
            'gender' => ['required', Rule::enum(Gender::class)],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * @param  array<string, mixed>  $input  code, name
     */
    public function saveProgram(?StudyProgram $program, array $input): StudyProgram
    {
        $data = Validator::make($input, self::programRules($program?->id))->validate();

        return StudyProgram::query()->updateOrCreate(['id' => $program?->id], $data);
    }

    public function deleteProgram(StudyProgram $program): void
    {
        $program->loadCount(['classGroups', 'subjects', 'students']);

        if ($program->class_groups_count + $program->subjects_count + $program->students_count > 0) {
            throw ValidationException::withMessages(['program' => __('This study program is still used by classes, subjects or mahasantri.')]);
        }

        $program->delete();
    }

    /**
     * @param  array<string, mixed>  $input  study_program_id, code, name, kitab, credits, is_active
     */
    public function saveSubject(?Subject $subject, array $input): Subject
    {
        $data = Validator::make($input, [...Subject::rules($subject?->id), 'is_active' => ['boolean']])->validate();

        return Subject::query()->updateOrCreate(['id' => $subject?->id], [
            'study_program_id' => $data['study_program_id'],
            'code' => $data['code'],
            'name' => $data['name'],
            'kitab' => filled($data['kitab'] ?? null) ? $data['kitab'] : null,
            'credits' => $data['credits'],
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);
    }

    /**
     * @param  array<string, mixed>  $input  name, capacity, gender, is_active
     */
    public function saveRoom(?Room $room, array $input): Room
    {
        $data = Validator::make($input, self::roomRules($room?->id), ['gender.required' => __('Choose Putra or Putri.')])->validate();

        return Room::query()->updateOrCreate(['id' => $room?->id], [
            'name' => trim((string) $data['name']),
            'capacity' => $data['capacity'],
            'gender' => $data['gender'],
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);
    }

    /**
     * Remove a class (soft delete: past enrolments still point to it), unless it has mahasantri now.
     */
    public function deleteClass(ClassGroup $classGroup): void
    {
        $active = Semester::active();

        if ($active && ClassEnrollment::query()->where('semester_id', $active->id)->where('class_group_id', $classGroup->id)->exists()) {
            throw ValidationException::withMessages(['class' => __('This class has mahasantri in the active semester.')]);
        }

        $classGroup->delete();
    }
}
