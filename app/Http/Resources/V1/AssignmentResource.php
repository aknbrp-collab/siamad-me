<?php

namespace App\Http\Resources\V1;

use App\Models\Assignment;
use App\Models\Submission;
use App\Support\ActiveRole;
use App\Support\ApiFiles;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An assignment with the mahasantri's own submission (loaded for them).
 *
 * @mixin Assignment
 */
class AssignmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Assignment $assignment */
        $assignment = $this->resource;
        /** @var Submission|null $submission */
        $submission = $assignment->relationLoaded('submissions') ? $assignment->submissions->first() : null;

        return [
            'id' => $assignment->id,
            'title' => $assignment->title,
            'instructions' => $assignment->instructions,
            'due_at' => $assignment->due_at->toIso8601String(),
            'allow_late' => $assignment->allow_late,
            'max_score' => $assignment->max_score,
            /** Whether a (new) submission is accepted now. */
            'open' => $assignment->due_at->isFuture() || $assignment->allow_late,
            'my_submission' => $submission === null ? null : [
                'id' => $submission->id,
                'text' => $submission->text,
                'file_name' => $submission->original_name,
                'file_url' => $submission->file_path === null ? null : ApiFiles::url($request->user(), ActiveRole::get(), 'submission', $submission->id),
                'submitted_at' => $submission->submitted_at->toIso8601String(),
                'is_late' => $submission->is_late,
                /** Null until graded; a graded submission cannot be replaced. */
                'score' => $submission->score,
                'feedback' => $submission->feedback,
                'graded_at' => $submission->graded_at?->toIso8601String(),
            ],
        ];
    }
}
