<?php

namespace App\Actions\Teaching;

use App\Enums\Role;
use App\Enums\TeachingDocumentKind;
use App\Enums\TeachingDocumentStatus;
use App\Models\CourseOffering;
use App\Models\TeachingDocument;
use App\Models\User;
use App\Support\Notify;
use App\Support\Upload;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Muhadhir teaching documents (v3 Step 7): the RPS and the signed journal
 * recap of every class, or of classes taught together (D14), uploaded as
 * versions and accepted or returned by Admin (D15).
 */
class ManageTeachingDocuments
{
    /**
     * The lead offering and the offerings taught with it, lead first.
     *
     * @return Collection<int, CourseOffering>
     */
    public function members(CourseOffering $offering): Collection
    {
        $leadId = $offering->teachingLeadId();

        return CourseOffering::query()->with('classGroup')
            ->where(fn ($query) => $query->whereKey($leadId)->orWhere('joined_to_id', $leadId))
            ->orderByRaw('case when id = ? then 0 else 1 end', [$leadId])
            ->orderBy('id')
            ->get();
    }

    /**
     * Join offerings of the same semester, subject and muhadhir that are taught
     * together. Groups they already belong to are merged; the lowest offering
     * becomes the lead. Documents already uploaded may stay only when they
     * belong to that lead (e.g. a putri class added later to a putra class
     * whose RPS is in, v4 D21).
     *
     * @param  list<int>  $offeringIds
     */
    public function join(array $offeringIds): CourseOffering
    {
        $picked = CourseOffering::query()->with('semester')->whereKey($offeringIds)->get();

        if ($picked->count() < 2) {
            $this->fail('join', __('Choose at least two classes to join.'));
        }

        $picked->first()->semester->assertOpen();

        if ($picked->pluck('semester_id')->unique()->count() > 1 || $picked->pluck('subject_id')->unique()->count() > 1 || $picked->pluck('teacher_id')->unique()->count() > 1) {
            $this->fail('join', __('Only classes of the same subject and muhadhir in one semester can be joined.'));
        }

        // Everything in the groups touched, so nothing is left behind.
        $leads = $picked->map(fn (CourseOffering $offering) => $offering->teachingLeadId())->unique()->values();
        $all = CourseOffering::query()->whereIn('id', $leads)->orWhereIn('joined_to_id', $leads)->get();

        $lead = $all->sortBy('id')->first();
        $documented = TeachingDocument::query()->whereIn('course_offering_id', $leads)->distinct()->pluck('course_offering_id');

        if ($documented->count() > 1 || ($documented->isNotEmpty() && (int) $documented->first() !== $lead->id)) {
            $this->fail('join', __('Documents were already uploaded for one of these classes; they can no longer be joined.'));
        }

        DB::transaction(function () use ($all, $lead) {
            foreach ($all as $offering) {
                $offering->update(['joined_to_id' => $offering->is($lead) ? null : $lead->id]);
            }
        });

        return $lead->refresh();
    }

    /**
     * Undo a join, as long as nothing was uploaded for the group.
     */
    public function split(CourseOffering $offering): void
    {
        $leadId = $offering->teachingLeadId();
        $offering->loadMissing('semester')->semester->assertOpen();

        if (TeachingDocument::query()->where('course_offering_id', $leadId)->exists()) {
            $this->fail('join', __('Documents were already uploaded for these classes; they stay joined.'));
        }

        CourseOffering::query()->where('joined_to_id', $leadId)->get()->each(fn (CourseOffering $member) => $member->update(['joined_to_id' => null]));
    }

    public function latest(CourseOffering $offering, TeachingDocumentKind $kind): ?TeachingDocument
    {
        return TeachingDocument::query()->where('course_offering_id', $offering->teachingLeadId())->where('kind', $kind)->orderByDesc('version')->first();
    }

    /**
     * Upload a new version (the muhadhir of the class). Earlier versions stay.
     */
    public function upload(CourseOffering $offering, TeachingDocumentKind $kind, mixed $file, User $by): TeachingDocument
    {
        $offering->loadMissing(['semester', 'teacher']);
        $offering->semester->assertOpen();

        if ($offering->teacher->user_id !== $by->id) {
            $this->fail('file', __('Only the muhadhir of this class can upload its documents.'));
        }

        Validator::make(['file' => $file], [
            'file' => ['required', 'file', 'mimes:pdf,doc,docx,jpg,jpeg,png', Upload::max()],
        ])->validate();

        /** @var UploadedFile $file */
        $leadId = $offering->teachingLeadId();

        $document = DB::transaction(function () use ($leadId, $kind, $file, $by) {
            // Lock the lead offering (aggregates cannot be locked on PostgreSQL) so two uploads get different versions.
            CourseOffering::query()->whereKey($leadId)->lockForUpdate()->first();
            $version = (int) TeachingDocument::query()->where('course_offering_id', $leadId)->where('kind', $kind)->max('version') + 1;
            $path = $file->storeAs("teaching/{$leadId}", "{$kind->value}-v{$version}-".now()->format('YmdHis').'.'.strtolower($file->getClientOriginalExtension()), 'local');

            if ($path === false) {
                throw new RuntimeException('The document could not be stored.');
            }

            return TeachingDocument::create([
                'course_offering_id' => $leadId,
                'kind' => $kind,
                'version' => $version,
                'file_path' => $path,
                'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                'uploaded_by' => $by->id,
                'status' => TeachingDocumentStatus::Waiting,
            ]);
        });

        Notify::send(
            User::query()->whereHas('roles', fn ($query) => $query->where('role', Role::Admin))->get(),
            'teaching',
            __(':kind from :name', ['kind' => $kind->short(), 'name' => $by->name]),
            __(':subject (:classes), version :version', [
                'subject' => $offering->subject_name,
                'classes' => $this->members($offering)->map(fn (CourseOffering $member) => $member->classGroup->name)->join(', '),
                'version' => $document->version,
            ]),
            route('teaching-documents', ['semester' => $offering->semester_id]),
            'document-arrow-up',
            $by,
        );

        return $document;
    }

    /**
     * Accept the latest version, or return it with a note for a new upload.
     */
    public function review(TeachingDocument $document, bool $accept, ?string $note, User $by): void
    {
        $document->loadMissing(['courseOffering.semester', 'uploader']);
        $document->courseOffering->semester->assertOpen();

        $newer = TeachingDocument::query()->where('course_offering_id', $document->course_offering_id)
            ->where('kind', $document->kind)->where('version', '>', $document->version)->exists();

        if ($newer || $document->status !== TeachingDocumentStatus::Waiting) {
            $this->fail('reviewNote', __('Only the latest version waiting for review can be reviewed.'));
        }

        $note = trim((string) $note);

        if (! $accept && mb_strlen($note) < 5) {
            $this->fail('reviewNote', __('Tell the muhadhir what to change (at least 5 characters).'));
        }

        $document->update([
            'status' => $accept ? TeachingDocumentStatus::Accepted : TeachingDocumentStatus::Returned,
            'reviewed_by' => $by->id,
            'reviewed_at' => now(),
            'review_note' => $note !== '' ? $note : null,
        ]);

        Notify::send(
            [$document->uploader],
            'teaching',
            $accept
                ? __(':kind accepted: :subject', ['kind' => $document->kind->short(), 'subject' => $document->courseOffering->subject_name])
                : __(':kind returned: :subject', ['kind' => $document->kind->short(), 'subject' => $document->courseOffering->subject_name]),
            $note !== '' ? $note : __('Version :version was accepted.', ['version' => $document->version]),
            route('ustadz.offerings.documents', $document->course_offering_id),
            $accept ? 'check-badge' : 'arrow-uturn-left',
            $by,
        );
    }

    private function fail(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
