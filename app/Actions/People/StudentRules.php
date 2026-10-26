<?php

namespace App\Actions\People;

use App\Enums\Gender;
use App\Enums\StudentStatus;
use App\Models\Student;
use App\Rules\UniqueUsername;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;

trait StudentRules
{
    /**
     * @return array<string, array<int, ValidationRule|string|Unique|Exists|Enum>>
     */
    protected function studentRules(?Student $student = null): array
    {
        return [
            'nim' => [
                'required', 'string', 'max:32', 'regex:/^[A-Za-z0-9.\-]+$/',
                Rule::unique('students', 'nim')->ignore($student?->id),
                new UniqueUsername($student?->user_id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($student?->user_id)],
            'study_program_id' => ['required', 'integer', Rule::exists('study_programs', 'id')->whereNull('deleted_at')],
            'entry_year' => ['required', 'integer', 'min:1990', 'max:'.(now()->year + 1)],
            'gender' => ['required', Rule::enum(Gender::class)],
            'birth_place' => ['nullable', 'string', 'max:100'],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'address' => ['nullable', 'string', 'max:500'],
            'phone' => ['nullable', 'string', 'max:30'],
            'father_name' => ['nullable', 'string', 'max:255'],
            'father_is_alive' => ['nullable', 'boolean'],
            'mother_name' => ['nullable', 'string', 'max:255'],
            'mother_is_alive' => ['nullable', 'boolean'],
            'guardian_name' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', Rule::enum(StudentStatus::class)],
            'graduated_at' => ['nullable', 'date', 'required_if:status,'.StudentStatus::Graduated->value],
            'photo' => ['nullable', 'image', 'max:1024'],
        ];
    }
}
