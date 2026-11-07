<?php

namespace App\Http\Controllers\Api\V1\Student;

use App\Actions\Lms\ManageAssignments;
use App\Http\Controllers\Api\V1\Student\Concerns\ResolvesStudent;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\AssignmentResource;
use App\Models\Assignment;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;

#[Group('Mahasantri', weight: 9)]
class AssignmentController extends Controller
{
    use ResolvesStudent;

    /**
     * Submit an assignment.
     *
     * Text and/or a file (multipart field `file`). Replaces an earlier submission until it is graded.
     */
    public function submit(Request $request, Assignment $assignment, ManageAssignments $assignments): AssignmentResource
    {
        $request->validate([
            'text' => ['nullable', 'string'],
            /** PDF, Word, image or archive, up to the upload limit. */
            'file' => ['nullable', 'file'],
        ]);
        $enrollment = $this->enrollment($request, $assignment->courseOffering);

        $assignments->submit($assignment, $enrollment, [
            'text' => $request->input('text') ?: null,
            'file' => $request->file('file'),
        ]);

        return new AssignmentResource($assignment->load(['submissions' => fn ($query) => $query->where('course_enrollment_id', $enrollment->id)]));
    }
}
