<?php

namespace App\Actions\Documents;

use App\Enums\DocumentStatus;
use App\Models\StudentDocument;
use App\Models\TeacherDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;

class UploadDocument
{
    /**
     * Allowed file types and size for KTP, KK and Ijazah scans.
     *
     * @return list<string>
     */
    public static function rules(): array
    {
        return ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:2048'];
    }

    /**
     * Store a (re-)upload and send it for verification. A previous file is
     * kept on disk for audit; only the pointer moves.
     */
    public function handle(StudentDocument|TeacherDocument $document, UploadedFile $file): void
    {
        Validator::make(['file' => $file], ['file' => self::rules()])->validate();

        $owner = $document instanceof StudentDocument
            ? "students/{$document->student_id}"
            : "teachers/{$document->teacher_id}";

        $document->update([
            'file_path' => $file->store("documents/{$owner}", 'local'),
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'status' => DocumentStatus::Uploaded,
            'uploaded_at' => now(),
            'verified_by' => null,
            'verified_at' => null,
            'rejection_note' => null,
        ]);
    }
}
