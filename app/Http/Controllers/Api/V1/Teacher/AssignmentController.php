<?php

namespace App\Http\Controllers\Api\V1\Teacher;

use App\Actions\Lms\ManageAssignments;
use App\Http\Controllers\Api\V1\Teacher\Concerns\TeachesOffering;
use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\CourseEnrollment;
use App\Models\CourseOffering;
use App\Models\Submission;
use App\Support\ActiveRole;
use App\Support\ApiFiles;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

#[Group('Muhadhir', weight: 11)]
class AssignmentController extends Controller
{
    use TeachesOffering;

    /**
     * Assignments of a class.
     *
     * With how many submitted and how many are graded, and the grade components an assignment can feed.
     */
    public function index(Request $request, CourseOffering $offering): JsonResponse
    {
        $this->authorizeOffering($request, $offering);
        $assignments = $offering->assignments()->withCount(['submissions', 'submissions as graded_count' => fn ($query) => $query->whereNotNull('graded_at')])->get();

        return response()->json(['data' => [
            'mahasantri_count' => $offering->enrollments()->count(),
            'grade_components' => $offering->components()->get(['id', 'name', 'weight'])->toArray(),
            'assignments' => $assignments->map(fn (Assignment $assignment) => [
                ...$this->assignment($assignment),
                'submissions_count' => $assignment->submissions_count,
                'graded_count' => (int) $assignment->getAttribute('graded_count'),
            ])->values()->all(),
        ]]);
    }

    /**
     * Create an assignment.
     *
     * The mahasantri are notified.
     */
    public function store(Request $request, CourseOffering $offering, ManageAssignments $assignments): JsonResponse
    {
        $this->authorizeOffering($request, $offering);
        $request->validate($this->rules());

        return response()->json(['data' => $this->assignment($assignments->save($offering, null, $this->input($request)))], 201);
    }

    /**
     * Change an assignment.
     */
    public function update(Request $request, CourseOffering $offering, Assignment $assignment, ManageAssignments $assignments): JsonResponse
    {
        $this->authorizeAssignment($request, $offering, $assignment);
        $request->validate($this->rules());

        return response()->json(['data' => $this->assignment($assignments->save($offering, $assignment, $this->input($request)))]);
    }

    /**
     * Delete an assignment.
     *
     * Only while nothing was submitted.
     */
    public function destroy(Request $request, CourseOffering $offering, Assignment $assignment, ManageAssignments $assignments): Response
    {
        $this->authorizeAssignment($request, $offering, $assignment);
        $assignments->delete($assignment);

        return response()->noContent();
    }

    /**
     * Submissions.
     *
     * Every mahasantri of the class with their submission (or null), its file link, score and feedback.
     */
    public function submissions(Request $request, CourseOffering $offering, Assignment $assignment): JsonResponse
    {
        $this->authorizeAssignment($request, $offering, $assignment);
        $user = $request->user();
        $role = ActiveRole::get();

        $enrollments = $offering->enrollments()
            ->with(['student.user', 'submissions' => fn ($query) => $query->where('assignment_id', $assignment->id)])
            ->get()->sortBy('student.user.name')->values();

        return response()->json(['data' => [
            'assignment' => $this->assignment($assignment),
            'mahasantri' => $enrollments->map(function (CourseEnrollment $enrollment) use ($user, $role) {
                /** @var Submission|null $submission */
                $submission = $enrollment->submissions->first();

                return [
                    'enrollment_id' => $enrollment->id,
                    'nim' => $enrollment->student->nim,
                    'name' => $enrollment->student->user->name,
                    'submission' => $submission === null ? null : [
                        'id' => $submission->id,
                        'text' => $submission->text,
                        'file_name' => $submission->original_name,
                        'file_url' => $submission->file_path === null ? null : ApiFiles::url($user, $role, 'submission', $submission->id),
                        'submitted_at' => $submission->submitted_at->toIso8601String(),
                        'is_late' => $submission->is_late,
                        'score' => $submission->score,
                        'feedback' => $submission->feedback,
                        'graded_at' => $submission->graded_at?->toIso8601String(),
                    ],
                ];
            })->all(),
        ]]);
    }

    /**
     * Grade a submission.
     *
     * `score` from 0 to the assignment's maximum, `feedback` optional; the mahasantri sees both.
     */
    public function grade(Request $request, CourseOffering $offering, Assignment $assignment, Submission $submission, ManageAssignments $assignments): Response
    {
        $this->authorizeAssignment($request, $offering, $assignment);
        abort_unless($submission->assignment_id === $assignment->id, 404);
        $request->validate(['score' => ['required', 'numeric'], 'feedback' => ['nullable', 'string', 'max:5000']]);

        $assignments->grade($submission, $request->input('score'), $request->input('feedback'), $request->user());

        return response()->noContent();
    }

    /**
     * Copy scores to the grade sheet.
     *
     * Graded scores, as a percentage, into the linked grade component. Returns how many were copied.
     */
    public function copyToGrades(Request $request, CourseOffering $offering, Assignment $assignment, ManageAssignments $assignments): JsonResponse
    {
        $this->authorizeAssignment($request, $offering, $assignment);

        return response()->json(['data' => ['copied' => $assignments->copyToGradeSheet($assignment)]]);
    }

    private function authorizeAssignment(Request $request, CourseOffering $offering, Assignment $assignment): void
    {
        $this->authorizeOffering($request, $offering);
        abort_unless($assignment->course_offering_id === $offering->id, 404);
    }

    /**
     * @return array<string, list<string>>
     */
    private function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'instructions' => ['nullable', 'string'],
            'due_at' => ['required', 'date'],
            'allow_late' => ['nullable', 'boolean'],
            'max_score' => ['required', 'integer'],
            /** The grade component the scores can be copied to. */
            'grade_component_id' => ['nullable', 'integer'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function input(Request $request): array
    {
        return [
            'title' => $request->input('title'),
            'instructions' => $request->input('instructions') ?: null,
            'due_at' => $request->input('due_at'),
            'allow_late' => $request->boolean('allow_late'),
            'max_score' => $request->integer('max_score'),
            'grade_component_id' => $request->integer('grade_component_id') ?: null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function assignment(Assignment $assignment): array
    {
        return [
            'id' => $assignment->id,
            'title' => $assignment->title,
            'instructions' => $assignment->instructions,
            'due_at' => $assignment->due_at->toIso8601String(),
            'allow_late' => $assignment->allow_late,
            'max_score' => $assignment->max_score,
            'grade_component_id' => $assignment->grade_component_id,
        ];
    }
}
