<?php

namespace App\Actions\People;

use App\Enums\Gender;
use App\Enums\TeacherStatus;
use App\Models\Teacher;
use App\Rules\UniqueUsername;
use App\Support\Upload;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\Unique;

trait TeacherRules
{
    /**
     * @return array<string, array<int, ValidationRule|string|Unique|Enum>>
     */
    protected function teacherRules(?Teacher $teacher = null): array
    {
        return [
            'nidm' => [
                'required', 'string', 'max:32', 'regex:/^[A-Za-z0-9.\-]+$/',
                Rule::unique('teachers', 'nidm')->ignore($teacher?->id),
                new UniqueUsername($teacher?->user_id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($teacher?->user_id)],
            'nik' => ['nullable', 'digits:16', Rule::unique('teachers', 'nik')->ignore($teacher?->id)],
            'title' => ['nullable', 'string', 'max:100'],
            'gender' => ['required', Rule::enum(Gender::class)],
            'birth_place' => ['nullable', 'string', 'max:100'],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'address' => ['nullable', 'string', 'max:500'],
            'phone' => ['nullable', 'string', 'max:30'],
            'expertise' => ['nullable', 'string', 'max:255'],
            'scopus_id' => ['nullable', 'string', 'max:50'],
            'google_scholar_url' => ['nullable', 'url', 'max:255'],
            'status' => ['sometimes', Rule::enum(TeacherStatus::class)],
            'photo' => ['nullable', 'image', Upload::max()],
        ];
    }
}
