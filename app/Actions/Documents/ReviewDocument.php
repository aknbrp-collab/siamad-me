<?php

namespace App\Actions\Documents;

use App\Enums\DocumentStatus;
use App\Models\StudentDocument;
use App\Models\TeacherDocument;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class ReviewDocument
{
    public function verify(StudentDocument|TeacherDocument $document, User $reviewer): void
    {
        $this->ensureReviewable($document);

        $document->update([
            'status' => DocumentStatus::Verified,
            'verified_by' => $reviewer->id,
            'verified_at' => now(),
            'rejection_note' => null,
        ]);
    }

    public function reject(StudentDocument|TeacherDocument $document, User $reviewer, string $note): void
    {
        $this->ensureReviewable($document);

        if (trim($note) === '') {
            throw ValidationException::withMessages(['rejectionNote' => __('Tell the owner why the document was rejected.')]);
        }

        $document->update([
            'status' => DocumentStatus::Rejected,
            'verified_by' => $reviewer->id,
            'verified_at' => now(),
            'rejection_note' => trim($note),
        ]);
    }

    private function ensureReviewable(StudentDocument|TeacherDocument $document): void
    {
        if ($document->status !== DocumentStatus::Uploaded) {
            throw ValidationException::withMessages(['document' => __('Only uploaded documents awaiting verification can be reviewed.')]);
        }
    }
}
