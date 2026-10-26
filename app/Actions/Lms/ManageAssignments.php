<?php

namespace App\Actions\Lms;

use App\Actions\Teaching\ManageGrades;
use App\Models\Assignment;
use App\Models\CourseEnrollment;
use App\Models\CourseOffering;
use App\Models\Submission;
use App\Models\User;
use App\Support\Notify;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ManageAssignments
{
    public function __construct(private readonly ManageGrades $grades) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function save(CourseOffering $offering, ?Assignment $assignment, array $input): Assignment
    {
        $offering->loadMissing('semester')->semester->assertOpen();

        $data = Validator::make($input, [
            'title' => ['required', 'string', 'max:255'],
            'instructions' => ['nullable', 'string', 'max:20000'],
            'due_at' => ['required', 'date'],
            'allow_late' => ['boolean'],
            'max_score' => ['required', 'integer', 'between:1,1000'],
            'grade_component_id' => ['nullable', 'integer', Rule::exists('grade_components', 'id')->where('course_offering_id', $offering->id)],
        ])->validate();

        if ($assignment !== null) {
            $assignment->update($data);

            return $assignment;
        }

        $created = $offering->assignments()->create($data);

        Notify::send(
            $offering->enrollments()->with('student.user')->get()->map(fn ($enrollment) => $enrollment->student->user),
            'work',
            __('New assignment: :title', ['title' => $created->title]),
            __(':subject · due :date', ['subject' => $offering->subject_name, 'date' => $created->due_at->translatedFormat('j M Y H:i')]),
            route('mahasantri.class', [$offering, 'tab' => 'assignments']),
            'clipboard-document-list',
        );

        return $created;
    }

    public function delete(Assignment $assignment): void
    {
        $assignment->loadMissing('courseOffering.semester')->courseOffering->semester->assertOpen();

        if ($assignment->submissions()->exists()) {
            $this->fail('assignment', __('This assignment already has submissions; it cannot be deleted.'));
        }

        $assignment->delete();
    }

    /**
     * Hand in (or replace, until graded) a submission.
     *
     * @param  array<string, mixed>  $input
     */
    public function submit(Assignment $assignment, CourseEnrollment $enrollment, array $input): Submission
    {
        $assignment->loadMissing('courseOffering.semester')->courseOffering->semester->assertOpen();

        if ($enrollment->course_offering_id !== $assignment->course_offering_id) {
            $this->fail('submission', __('You are not enrolled in this class.'));
        }

        if ($assignment->isPastDue() && ! $assignment->allow_late) {
            $this->fail('submission', __('The deadline has passed.'));
        }

        $data = Validator::make($input, [
            'text' => ['nullable', 'string', 'max:20000'],
            'file' => ['nullable', 'file', 'mimes:pdf,doc,docx,jpg,jpeg,png,zip', 'max:10240'],
        ])->validate();

        $file = $data['file'] ?? null;

        if (blank($data['text'] ?? null) && ! $file instanceof UploadedFile) {
            $this->fail('submission', __('Write an answer or attach a file.'));
        }

        return DB::transaction(function () use ($assignment, $enrollment, $data, $file) {
            $submission = Submission::query()->firstOrNew([
                'assignment_id' => $assignment->id,
                'course_enrollment_id' => $enrollment->id,
            ]);

            if ($submission->graded_at !== null) {
                $this->fail('submission', __('This submission is already graded and can no longer be changed.'));
            }

            $submission->fill([
                'text' => $data['text'] ?? null,
                'submitted_at' => now(),
                'is_late' => $assignment->isPastDue(),
            ]);

            if ($file instanceof UploadedFile) {
                $path = $file->store("submissions/{$assignment->id}", 'local');

                if ($path === false) {
                    throw new RuntimeException('The file could not be stored.');
                }

                $submission->file_path = $path;
                $submission->original_name = mb_substr($file->getClientOriginalName(), 0, 255);
            }

            $submission->save();

            return $submission;
        });
    }

    public function grade(Submission $submission, mixed $score, ?string $feedback, User $grader): void
    {
        $submission->loadMissing('assignment.courseOffering.semester');
        $submission->assignment->courseOffering->semester->assertOpen();

        $max = $submission->assignment->max_score;
        $normalized = str_replace(',', '.', trim((string) $score));
        $value = is_numeric($normalized) ? round((float) $normalized, 2) : null;

        if ($value === null || $value < 0 || $value > $max) {
            $this->fail('score', __('Give a score from 0 to :max.', ['max' => $max]));
        }

        $submission->update([
            'score' => $value,
            'feedback' => filled($feedback) ? $feedback : null,
            'graded_at' => now(),
            'graded_by' => $grader->id,
        ]);
    }

    /**
     * Copy graded scores (as a percentage) into the linked grade component.
     * Ungraded or missing submissions leave the grade sheet unchanged.
     */
    public function copyToGradeSheet(Assignment $assignment): int
    {
        if ($assignment->grade_component_id === null) {
            $this->fail('assignment', __('Link this assignment to a grade component first.'));
        }

        $scores = $assignment->submissions()
            ->whereNotNull('graded_at')
            ->get()
            ->mapWithKeys(fn (Submission $submission) => [
                $submission->course_enrollment_id => [
                    $assignment->grade_component_id => round((float) $submission->score / $assignment->max_score * 100, 2),
                ],
            ])
            ->all();

        $this->grades->saveScores($assignment->loadMissing('courseOffering')->courseOffering, $scores);

        return count($scores);
    }

    private function fail(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
