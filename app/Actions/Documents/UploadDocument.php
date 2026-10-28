<?php

namespace App\Actions\Documents;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\EducationLevel;
use App\Models\StudentDocument;
use App\Models\Teacher;
use App\Models\TeacherDocument;
use App\Support\Upload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UploadDocument
{
    /**
     * Allowed file types and size for KTP, KK and Ijazah scans.
     *
     * @return list<string>
     */
    public static function rules(): array
    {
        return ['required', 'file', 'mimes:pdf,jpg,jpeg,png', Upload::max()];
    }

    /**
     * Store a (re-)upload and send it for verification. A previous file is
     * kept on disk for audit; only the pointer moves. An ijazah also needs its
     * level, institution and graduation year (and, for muhadhir, the gelar).
     *
     * @param  array<string, mixed>  $details
     */
    public function handle(StudentDocument|TeacherDocument $document, UploadedFile $file, array $details = []): void
    {
        Validator::make(['file' => $file], ['file' => self::rules()])->validate();

        $ijazah = $document->type === DocumentType::Ijazah ? $this->ijazahDetails($document, $details) : [];

        $owner = $document instanceof StudentDocument
            ? "students/{$document->student_id}"
            : "teachers/{$document->teacher_id}";

        $document->update([
            ...$ijazah,
            'file_path' => $file->store("documents/{$owner}", 'local'),
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'status' => DocumentStatus::Uploaded,
            'uploaded_at' => now(),
            'verified_by' => null,
            'verified_at' => null,
            'rejection_note' => null,
        ]);
    }

    /**
     * A muhadhir adds another ijazah (one per level, v4 D22).
     */
    public function addIjazah(Teacher $teacher): TeacherDocument
    {
        $ijazah = $teacher->documents()->where('type', DocumentType::Ijazah)->get();

        if ($ijazah->contains(fn (TeacherDocument $document) => $document->education_level === null)) {
            throw ValidationException::withMessages(['ijazah' => __('Fill in and upload the empty ijazah first.')]);
        }

        if ($ijazah->count() >= count(EducationLevel::cases())) {
            throw ValidationException::withMessages(['ijazah' => __('Every level already has an ijazah.')]);
        }

        return $teacher->documents()->create(['type' => DocumentType::Ijazah]);
    }

    /**
     * Remove an extra ijazah that was added by mistake: only while nothing is
     * uploaded, and never the last one.
     */
    public function removeIjazah(TeacherDocument $document): void
    {
        $others = TeacherDocument::query()->where('teacher_id', $document->teacher_id)
            ->where('type', DocumentType::Ijazah)->whereKeyNot($document->id)->exists();

        if ($document->type !== DocumentType::Ijazah || $document->file_path !== null || ! $others) {
            throw ValidationException::withMessages(['ijazah' => __('Only an extra ijazah with nothing uploaded can be removed.')]);
        }

        $document->delete();
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array{education_level: string, institution: string, graduation_year: int, degree_title: string|null}
     */
    private function ijazahDetails(StudentDocument|TeacherDocument $document, array $details): array
    {
        $isTeacher = $document instanceof TeacherDocument;
        $levels = $isTeacher ? EducationLevel::cases() : EducationLevel::forStudents();
        $ownerColumn = $isTeacher ? 'teacher_id' : 'student_id';
        $ownerId = $document instanceof TeacherDocument ? $document->teacher_id : $document->student_id;

        $data = Validator::make($details, [
            'education_level' => [
                'required', Rule::in(array_map(fn (EducationLevel $level) => $level->value, $levels)),
                Rule::unique($document->getTable())
                    ->where($ownerColumn, $ownerId)
                    ->where('type', DocumentType::Ijazah->value)
                    ->ignore($document->id),
            ],
            'institution' => ['required', 'string', 'max:150'],
            'graduation_year' => ['required', 'integer', 'between:1950,'.now()->year],
            'degree_title' => ['nullable', 'string', 'max:50'],
        ], [
            'education_level.unique' => __('You already have an ijazah for this level.'),
        ], [
            'education_level' => __('level'),
            'institution' => __('institution'),
            'graduation_year' => __('graduation year'),
            'degree_title' => __('gelar'),
        ])->validate();

        return [
            'education_level' => $data['education_level'],
            'institution' => trim($data['institution']),
            'graduation_year' => (int) $data['graduation_year'],
            'degree_title' => $isTeacher && filled($data['degree_title'] ?? null) ? trim($data['degree_title']) : null,
        ];
    }
}
