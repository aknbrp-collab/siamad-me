<?php

namespace App\Actions\People;

use App\Models\Guardian;
use App\Models\Student;
use App\Models\Teacher;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Profile changes made by the person themselves (Profil saya). Only the
 * fields listed here can change; everything else in the input is dropped
 * before validation, so a crafted request cannot touch login IDs, academic
 * data or status. The academic office edits the rest on the admin pages.
 */
class UpdateOwnProfile
{
    use StoresProfilePhoto, StudentRules, TeacherRules;

    /** Mahasantri: Pribadi and Keluarga. Name, gender and Akademik stay with the academic office. */
    public const STUDENT_FIELDS = [
        'birth_place', 'birth_date', 'phone', 'address', 'photo',
        'father_name', 'father_is_alive', 'mother_name', 'mother_is_alive', 'guardian_name',
    ];

    /** Muhadhir: everything except the NIDM (login ID) and the employment status. */
    public const TEACHER_FIELDS = [
        'name', 'email', 'nik', 'title', 'gender',
        'birth_place', 'birth_date', 'phone', 'address', 'photo',
        'expertise', 'scopus_id', 'google_scholar_url',
    ];

    /** Wali santri: everything except the login code and the linked mahasantri. */
    public const GUARDIAN_FIELDS = ['name', 'email', 'relationship', 'phone'];

    /**
     * @param  array<string, mixed>  $input
     */
    public function student(Student $student, array $input): void
    {
        $data = Validator::make(
            Arr::only($input, self::STUDENT_FIELDS),
            Arr::only($this->studentRules($student), self::STUDENT_FIELDS),
        )->validate();

        $student->fill(Arr::except($data, ['photo']));

        if ($path = $this->storePhoto($data['photo'] ?? null, 'students')) {
            $student->photo_path = $path;
        }

        $student->save();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function teacher(Teacher $teacher, array $input): void
    {
        $data = Validator::make(
            Arr::only($input, self::TEACHER_FIELDS),
            Arr::only($this->teacherRules($teacher), self::TEACHER_FIELDS),
        )->validate();

        DB::transaction(function () use ($teacher, $data) {
            $teacher->user->update(['name' => $data['name'], 'email' => $data['email'] ?? null]);

            $teacher->fill(Arr::except($data, ['name', 'email', 'photo']));

            if ($path = $this->storePhoto($data['photo'] ?? null, 'teachers')) {
                $teacher->photo_path = $path;
            }

            $teacher->save();
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function guardian(Guardian $guardian, array $input): void
    {
        $data = Validator::make(Arr::only($input, self::GUARDIAN_FIELDS), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($guardian->user_id)],
            'relationship' => ['nullable', 'string', 'max:50'],
            'phone' => ['nullable', 'string', 'max:30'],
        ])->validate();

        DB::transaction(function () use ($guardian, $data) {
            $guardian->user->update(['name' => $data['name'], 'email' => $data['email'] ?? null]);
            $guardian->update(Arr::only($data, ['relationship', 'phone']));
        });
    }
}
