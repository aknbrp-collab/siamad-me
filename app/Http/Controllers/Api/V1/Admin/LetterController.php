<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Letters\ManageLetters;
use App\Enums\LetterRequestStatus;
use App\Http\Controllers\Controller;
use App\Models\IncomingLetter;
use App\Models\Letter;
use App\Models\LetterRequest;
use App\Models\LetterType;
use App\Models\Student;
use App\Models\Teacher;
use App\Support\ActiveRole;
use App\Support\ApiFiles;
use App\Support\LetterTemplate;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

/**
 * Persuratan (Admin, Superadmin): requests from mahasantri, issuing and
 * cancelling letters (numbered automatically), and incoming letters.
 */
#[Group('Admin akademik', weight: 15)]
class LetterController extends Controller
{
    /**
     * Letter types and waiting requests.
     *
     * Every letter type (with its default signatory and stamp), and the mahasantri requests waiting, oldest
     * first.
     */
    public function overview(): JsonResponse
    {
        return response()->json(['data' => [
            'types' => LetterType::query()->orderBy('name')->get()->map(fn (LetterType $type) => [
                ...$type->only(['id', 'name', 'code', 'requestable', 'is_active', 'signatory_id', 'stamp_id', 'print_marks']),
                'subject' => $type->subject->value,
            ])->values()->all(),
            'requests' => LetterRequest::query()->with(['student.user', 'type'])->where('status', LetterRequestStatus::Pending)->orderBy('created_at')->get()
                ->map(fn (LetterRequest $item) => [
                    'id' => $item->id,
                    'type_id' => $item->letter_type_id,
                    'type' => $item->type->name,
                    'student_id' => $item->student_id,
                    'nim' => $item->student->nim,
                    'name' => $item->student->user->name,
                    'purpose' => $item->purpose,
                    'created_at' => $item->created_at->toIso8601String(),
                ])->values()->all(),
        ]]);
    }

    /**
     * Letters issued.
     *
     * Of a `year` (default this year), `type` filter and `search` on number, subject, recipient or person;
     * paginated, newest number first, with the PDF link.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['year' => ['nullable', 'integer'], 'type' => ['nullable', 'integer'], 'search' => ['nullable', 'string']]);
        $term = '%'.mb_strtolower(trim((string) $request->input('search'))).'%';
        $user = $request->user();
        $role = ActiveRole::get();

        $letters = Letter::query()->with(['type', 'student.user', 'teacher.user', 'issuer'])
            ->where('year', $request->integer('year', now()->year))
            ->when($request->filled('type'), fn ($query) => $query->where('letter_type_id', $request->integer('type')))
            ->when($request->filled('search'), fn ($query) => $query->where(fn ($query) => $query->whereRaw('lower(number) like ?', [$term])
                ->orWhereRaw('lower(subject_line) like ?', [$term])
                ->orWhereRaw('lower(coalesce(recipient, \'\')) like ?', [$term])
                ->orWhereHas('student.user', fn ($query) => $query->whereRaw('lower(name) like ?', [$term]))
                ->orWhereHas('teacher.user', fn ($query) => $query->whereRaw('lower(name) like ?', [$term]))))
            ->orderByDesc('sequence')->paginate(25);

        return response()->json([
            'data' => collect($letters->items())->map(fn (Letter $letter) => [
                'id' => $letter->id,
                'number' => $letter->number,
                'type' => $letter->type->name,
                'subject_line' => $letter->subject_line,
                'person' => $letter->student?->user->name ?? $letter->teacher?->user->name,
                'recipient' => $letter->recipient,
                'issued_on' => $letter->issued_on->toDateString(),
                'issued_by' => $letter->issuer->name,
                'cancelled' => $letter->cancelled_at !== null,
                'cancel_reason' => $letter->cancel_reason,
                'pdf_url' => ApiFiles::download($user, $role, 'letter', ['letter' => $letter->id]),
            ])->values()->all(),
            'meta' => ['current_page' => $letters->currentPage(), 'last_page' => $letters->lastPage(), 'total' => $letters->total()],
        ]);
    }

    /**
     * Draft a letter.
     *
     * The type's template filled in for `student_id` or `teacher_id`, the date and the `purpose` (e.g. from a
     * request), with the type's subject line, signatory and stamp: show it for editing, then issue it.
     */
    public function draft(Request $request): JsonResponse
    {
        $request->validate(['letter_type_id' => ['required', 'integer'], 'student_id' => ['nullable', 'integer'], 'teacher_id' => ['nullable', 'integer'], 'issued_on' => ['nullable', 'date'], 'purpose' => ['nullable', 'string']]);
        $type = LetterType::query()->findOrFail($request->integer('letter_type_id'));

        return response()->json(['data' => [
            'subject_line' => $type->name,
            'signatory_id' => $type->signatory_id,
            'stamp_id' => $type->stamp_id,
            'print_marks' => $type->print_marks,
            'body' => LetterTemplate::fill(
                $type->template,
                $request->filled('student_id') ? Student::query()->find($request->integer('student_id')) : null,
                $request->filled('teacher_id') ? Teacher::query()->find($request->integer('teacher_id')) : null,
                $request->filled('issued_on') ? Carbon::parse((string) $request->input('issued_on')) : now(),
                $request->input('purpose'),
            ),
        ]]);
    }

    /**
     * Issue a letter.
     *
     * `letter_type_id`, `student_id` or `teacher_id` (or a `recipient`), `subject_line`, `body`, `issued_on`,
     * `signatory_id`, `stamp_id`, `print_marks`, and `letter_request_id` when it answers a request (the
     * mahasantri is notified). The number is given in order.
     */
    public function issue(Request $request, ManageLetters $letters): JsonResponse
    {
        $request->validate(['letter_type_id' => ['required', 'integer'], 'subject_line' => ['required', 'string'], 'body' => ['required', 'string'], 'issued_on' => ['required', 'date'], 'letter_request_id' => ['nullable', 'integer']]);
        $source = $request->filled('letter_request_id') ? LetterRequest::query()->findOrFail($request->integer('letter_request_id')) : null;

        $letter = $letters->issue([
            ...$request->only(['letter_type_id', 'student_id', 'teacher_id', 'recipient', 'subject_line', 'body', 'issued_on', 'signatory_id', 'stamp_id']),
            'print_marks' => $request->boolean('print_marks', true),
        ], $request->user(), $source);

        return response()->json(['data' => [
            'id' => $letter->id,
            'number' => $letter->number,
            'pdf_url' => ApiFiles::download($request->user(), ActiveRole::get(), 'letter', ['letter' => $letter->id]),
        ]], 201);
    }

    /**
     * Cancel a letter.
     *
     * With a `reason`; the number is not reused.
     */
    public function cancel(Request $request, Letter $letter, ManageLetters $letters): Response
    {
        $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $letters->cancel($letter, (string) $request->input('reason'), $request->user());

        return response()->noContent();
    }

    /**
     * Reject a request.
     *
     * With a `reason`; the mahasantri is notified.
     */
    public function reject(Request $request, LetterRequest $letterRequest, ManageLetters $letters): Response
    {
        $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $letters->reject($letterRequest, (string) $request->input('reason'), $request->user());

        return response()->noContent();
    }

    /**
     * Incoming letters.
     *
     * Of a `year` (default this year), `open=1` for those whose disposition is not done, `search`; paginated.
     */
    public function incoming(Request $request): JsonResponse
    {
        $request->validate(['year' => ['nullable', 'integer'], 'open' => ['nullable', 'boolean'], 'search' => ['nullable', 'string']]);
        $term = '%'.mb_strtolower(trim((string) $request->input('search'))).'%';
        $user = $request->user();
        $role = ActiveRole::get();

        $letters = IncomingLetter::query()
            ->whereYear('received_on', $request->integer('year', now()->year))
            ->when($request->boolean('open'), fn ($query) => $query->whereNull('disposition_done_at'))
            ->when($request->filled('search'), fn ($query) => $query->where(fn ($query) => $query->whereRaw('lower(agenda_number) like ?', [$term])
                ->orWhereRaw('lower(sender) like ?', [$term])
                ->orWhereRaw('lower(subject) like ?', [$term])
                ->orWhereRaw('lower(coalesce(sender_number, \'\')) like ?', [$term])))
            ->orderByDesc('received_on')->orderByDesc('id')->paginate(25);

        return response()->json([
            'data' => collect($letters->items())->map(fn (IncomingLetter $letter) => [
                ...$letter->only(['id', 'agenda_number', 'sender', 'sender_number', 'subject', 'disposition_to', 'disposition_note']),
                'received_on' => $letter->received_on->toDateString(),
                'sender_date' => $letter->sender_date?->toDateString(),
                'done' => $letter->disposition_done_at !== null,
                'scan_url' => $letter->scan_path === null ? null : ApiFiles::url($user, $role, 'incoming-letter', $letter->id),
            ])->values()->all(),
            'meta' => ['current_page' => $letters->currentPage(), 'last_page' => $letters->lastPage(), 'total' => $letters->total()],
        ]);
    }

    /**
     * Record or change an incoming letter.
     *
     * Multipart: `received_on`, `sender`, `sender_number`, `sender_date`, `subject`, `scan` (PDF/photo),
     * `disposition_to`, `disposition_note`; `done: true` when the disposition is finished. Without `id`: a new
     * letter with the next agenda number.
     */
    public function saveIncoming(Request $request, ManageLetters $letters): JsonResponse
    {
        $request->validate(['id' => ['nullable', 'integer'], 'received_on' => ['required', 'date'], 'sender' => ['required', 'string'], 'subject' => ['required', 'string'], 'scan' => ['nullable', 'file'], 'done' => ['nullable', 'boolean']]);
        $input = [
            'received_on' => $request->input('received_on'),
            'sender' => trim((string) $request->input('sender')),
            'sender_number' => trim((string) $request->input('sender_number')) ?: null,
            'sender_date' => $request->input('sender_date') ?: null,
            'subject' => trim((string) $request->input('subject')),
            'scan' => $request->file('scan'),
            'disposition_to' => trim((string) $request->input('disposition_to')) ?: null,
            'disposition_note' => trim((string) $request->input('disposition_note')) ?: null,
        ];

        $letter = $request->filled('id')
            ? $letters->updateIncoming(IncomingLetter::query()->findOrFail($request->integer('id')), $input, $request->boolean('done'))
            : $letters->recordIncoming($input, $request->user());

        return response()->json(['data' => ['id' => $letter->id, 'agenda_number' => $letter->agenda_number]], $request->filled('id') ? 200 : 201);
    }
}
