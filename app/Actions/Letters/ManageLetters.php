<?php

namespace App\Actions\Letters;

use App\Enums\LetterRequestStatus;
use App\Enums\LetterSubject;
use App\Enums\Role;
use App\Models\IncomingLetter;
use App\Models\Letter;
use App\Models\LetterRequest;
use App\Models\LetterType;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use App\Support\LetterNumbering;
use App\Support\Notify;
use App\Support\NumberSequence;
use App\Support\Upload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Letters (v3 D10): letter types and numbering (Superadmin), issuing and
 * cancelling outgoing letters, mahasantri requests, and the incoming letter
 * log (Admin). Issued letters are never edited or deleted.
 */
class ManageLetters
{
    /**
     * @param  array<string, mixed>  $input  name, code, subject, requestable, template, signatory_id, stamp_id, print_marks, is_active
     */
    public function saveType(?LetterType $type, array $input): LetterType
    {
        // Codes are upper case, so "ska" and "SKA" cannot both exist.
        $input['code'] = strtoupper(trim((string) ($input['code'] ?? '')));

        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:150'],
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9.\-]+$/', Rule::unique('letter_types', 'code')->ignore($type?->id)],
            'subject' => ['required', Rule::enum(LetterSubject::class)],
            'requestable' => ['boolean'],
            'template' => ['required', 'string', 'max:10000'],
            'signatory_id' => ['nullable', 'integer', Rule::exists('signatories', 'id')],
            'stamp_id' => ['nullable', 'integer', Rule::exists('stamps', 'id')],
            'print_marks' => ['boolean'],
            'is_active' => ['boolean'],
        ], [
            'code.regex' => __('Use letters, digits, dots and dashes only.'),
        ])->validate();

        if (($data['requestable'] ?? false) && $data['subject'] !== LetterSubject::Student->value) {
            throw ValidationException::withMessages(['requestable' => __('Only letters about a mahasantri can be requested by mahasantri.')]);
        }

        return LetterType::query()->updateOrCreate(['id' => $type?->id], $data);
    }

    /**
     * The number format (D12), its zero padding, and the first number of a year.
     */
    public function saveNumbering(string $format, int $padding, int $year, int $start): void
    {
        $format = trim($format);
        LetterNumbering::validate($format);

        Validator::make(['padding' => $padding, 'year' => $year, 'start' => $start], [
            'padding' => ['integer', 'between:1,6'],
            'year' => ['integer', 'between:2000,2100'],
            'start' => ['integer', 'between:1,999999'],
        ])->validate();

        foreach (['letters.number_format' => $format, 'letters.padding' => $padding] as $key => $value) {
            if (Setting::get($key) !== $value) {
                Setting::set($key, $value);
            }
        }

        if ($start !== LetterNumbering::startFor($year)) {
            $used = (int) Letter::query()->where('year', $year)->max('sequence');

            if ($start <= $used) {
                throw ValidationException::withMessages(['startNumber' => __('Number :used is already used in :year; start after it.', ['used' => $used, 'year' => $year])]);
            }

            Setting::set('letters.start.'.$year, $start);
        }
    }

    /**
     * Take the number, fill in the final text and store the letter.
     *
     * @param  array<string, mixed>  $input  letter_type_id, student_id, teacher_id, recipient, subject_line, body, issued_on, signatory_id, stamp_id, print_marks
     */
    public function issue(array $input, User $by, ?LetterRequest $request = null): Letter
    {
        $type = LetterType::query()->where('is_active', true)->whereKey((int) ($input['letter_type_id'] ?? 0))->first();

        if ($type === null) {
            throw ValidationException::withMessages(['letter_type_id' => __('Choose a letter type.')]);
        }

        $data = Validator::make($input, [
            'student_id' => [Rule::requiredIf($type->subject === LetterSubject::Student), 'nullable', 'integer', Rule::exists('students', 'id')],
            'teacher_id' => [Rule::requiredIf($type->subject === LetterSubject::Teacher), 'nullable', 'integer', Rule::exists('teachers', 'id')],
            'recipient' => ['nullable', 'string', 'max:255'],
            'subject_line' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:20000'],
            'issued_on' => ['required', 'date', 'before_or_equal:today'],
            'signatory_id' => ['nullable', 'integer', Rule::exists('signatories', 'id')->where('is_active', true)],
            'stamp_id' => ['nullable', 'integer', Rule::exists('stamps', 'id')->where('is_active', true)],
            'print_marks' => ['boolean'],
        ], [
            'student_id.required' => __('Choose the mahasantri this letter is about.'),
            'teacher_id.required' => __('Choose the muhadhir this letter is about.'),
        ])->validate();

        if ($request !== null && $request->status !== LetterRequestStatus::Pending) {
            throw ValidationException::withMessages(['letter_type_id' => __('This request was already handled.')]);
        }

        $date = Carbon::parse($data['issued_on']);

        $letter = DB::transaction(function () use ($type, $data, $date, $by, $request) {
            $number = LetterNumbering::take($type->code, $date);

            $letter = Letter::create([
                ...$number,
                'letter_type_id' => $type->id,
                'student_id' => $type->subject === LetterSubject::Student ? $data['student_id'] : null,
                'teacher_id' => $type->subject === LetterSubject::Teacher ? $data['teacher_id'] : null,
                'letter_request_id' => $request?->id,
                'recipient' => isset($data['recipient']) ? trim($data['recipient']) : null,
                'subject_line' => trim($data['subject_line']),
                'body' => rtrim($data['body']),
                'issued_on' => $date->toDateString(),
                'issued_by' => $by->id,
                'signatory_id' => $data['signatory_id'] ?? null,
                'stamp_id' => $data['stamp_id'] ?? null,
                'print_marks' => (bool) ($data['print_marks'] ?? true),
            ]);

            $request?->update(['status' => LetterRequestStatus::Approved, 'decided_by' => $by->id, 'decided_at' => now()]);

            return $letter;
        });

        if ($request !== null) {
            Notify::send([$request->loadMissing('student.user')->student->user], 'letter',
                __(':type is ready', ['type' => $type->name]),
                __('Letter :number can be downloaded from My letters.', ['number' => $letter->number]),
                route('mahasantri.letters'), 'document-text', $by);
        }

        return $letter;
    }

    /**
     * A wrong letter is cancelled with a reason; its number stays in the register.
     */
    public function cancel(Letter $letter, string $reason, User $by): void
    {
        if ($letter->isCancelled()) {
            throw ValidationException::withMessages(['cancelReason' => __('This letter is already cancelled.')]);
        }

        if (mb_strlen(trim($reason)) < 5) {
            throw ValidationException::withMessages(['cancelReason' => __('Give the reason for cancelling (at least 5 characters).')]);
        }

        $letter->update(['cancelled_at' => now(), 'cancelled_by' => $by->id, 'cancel_reason' => trim($reason)]);
    }

    public function request(Student $student, int $typeId, string $purpose): LetterRequest
    {
        $type = LetterType::query()->where('is_active', true)->where('requestable', true)->find($typeId);

        if ($type === null) {
            throw ValidationException::withMessages(['requestTypeId' => __('Choose a letter you can request.')]);
        }

        if (mb_strlen(trim($purpose)) < 5) {
            throw ValidationException::withMessages(['purpose' => __('Write what the letter is for (at least 5 characters).')]);
        }

        $pending = LetterRequest::query()->where('student_id', $student->id)->where('letter_type_id', $type->id)
            ->where('status', LetterRequestStatus::Pending)->exists();

        if ($pending) {
            throw ValidationException::withMessages(['requestTypeId' => __('You already have a request for this letter waiting.')]);
        }

        $request = LetterRequest::create(['student_id' => $student->id, 'letter_type_id' => $type->id, 'purpose' => trim(mb_substr($purpose, 0, 500))]);

        Notify::send(
            User::query()->whereHas('roles', fn ($query) => $query->where('role', Role::Admin))->get(),
            'letter',
            __('Letter request: :type', ['type' => $type->name]),
            __(':name (:nim): :purpose', ['name' => $student->loadMissing('user')->user->name, 'nim' => $student->nim, 'purpose' => $request->purpose]),
            route('admin.letters', ['tab' => 'requests']),
            'document-text',
            $student->user,
            'letter-requests',
            __(':count letter requests are waiting.'),
        );

        return $request;
    }

    public function reject(LetterRequest $request, string $reason, User $by): void
    {
        if ($request->status !== LetterRequestStatus::Pending) {
            throw ValidationException::withMessages(['rejectReason' => __('This request was already handled.')]);
        }

        if (mb_strlen(trim($reason)) < 5) {
            throw ValidationException::withMessages(['rejectReason' => __('Give the reason (at least 5 characters).')]);
        }

        $request->update(['status' => LetterRequestStatus::Rejected, 'decided_by' => $by->id, 'decided_at' => now(), 'reject_reason' => trim($reason)]);

        Notify::send([$request->loadMissing(['student.user', 'type'])->student->user], 'letter',
            __('Letter request not granted'),
            __(':type: :reason', ['type' => $request->type->name, 'reason' => trim($reason)]),
            route('mahasantri.letters'), 'x-circle', $by);
    }

    /**
     * @param  array<string, mixed>  $input  received_on, sender, sender_number, sender_date, subject, scan, disposition_to, disposition_note
     */
    public function recordIncoming(array $input, User $by): IncomingLetter
    {
        $data = $this->validateIncoming($input);
        $received = Carbon::parse($data['received_on']);

        return DB::transaction(fn () => IncomingLetter::create([
            ...collect($data)->except('scan')->all(),
            'agenda_number' => NumberSequence::next('AGD', $received->year),
            'scan_path' => $this->storeScan($data['scan'] ?? null),
            'recorded_by' => $by->id,
        ]));
    }

    /**
     * Correct the details or the disposition; the agenda number never changes.
     *
     * @param  array<string, mixed>  $input
     */
    public function updateIncoming(IncomingLetter $letter, array $input, bool $done): IncomingLetter
    {
        $data = $this->validateIncoming($input);
        $scan = $this->storeScan($data['scan'] ?? null);

        $letter->update([
            ...collect($data)->except('scan')->all(),
            'scan_path' => $scan ?? $letter->scan_path,
            'disposition_done_at' => $done ? ($letter->disposition_done_at ?? now()) : null,
        ]);

        return $letter;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function validateIncoming(array $input): array
    {
        return Validator::make($input, [
            'received_on' => ['required', 'date', 'before_or_equal:today'],
            'sender' => ['required', 'string', 'max:255'],
            'sender_number' => ['nullable', 'string', 'max:100'],
            'sender_date' => ['nullable', 'date'],
            'subject' => ['required', 'string', 'max:500'],
            'scan' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', Upload::max()],
            'disposition_to' => ['nullable', 'string', 'max:255'],
            'disposition_note' => ['nullable', 'string', 'max:2000'],
        ])->validate();
    }

    private function storeScan(mixed $scan): ?string
    {
        if (! $scan instanceof UploadedFile) {
            return null;
        }

        $path = $scan->store('letters/incoming/'.now()->format('Y'), 'local');

        if ($path === false) {
            throw new RuntimeException('The scan could not be stored.');
        }

        return $path;
    }
}
