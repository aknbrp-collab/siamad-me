<?php

namespace App\Actions\Teaching;

use App\Enums\AttendanceStatus;
use App\Enums\MeetingMode;
use App\Enums\MeetingProvider;
use App\Models\ClassSession;
use App\Models\CourseOffering;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ManageMeetings
{
    /**
     * Create (numbered automatically) or update a meeting.
     *
     * @param  array<string, mixed>  $input
     */
    public function save(CourseOffering $offering, ?ClassSession $session, array $input): ClassSession
    {
        $offering->loadMissing('semester')->semester->assertOpen();

        $online = ($input['mode'] ?? null) === MeetingMode::Online->value;

        $data = Validator::make($input, [
            'held_on' => ['required', 'date'],
            'topic' => ['nullable', 'string', 'max:255'],
            'mode' => ['required', Rule::enum(MeetingMode::class)],
            'meeting_provider' => [Rule::requiredIf($online), 'nullable', Rule::enum(MeetingProvider::class)],
            'meeting_url' => [Rule::requiredIf($online), 'nullable', 'url:https', 'max:255'],
            // Jurnal mengajar (v3 Step 7).
            'material' => ['nullable', 'string', 'max:2000'],
            'method' => ['nullable', 'string', 'max:255'],
            'journal_note' => ['nullable', 'string', 'max:2000'],
        ], [
            'meeting_url.url' => __('Paste the full https:// link of the meeting.'),
        ])->validate();

        if (! $online) {
            $data['meeting_provider'] = null;
            $data['meeting_url'] = null;
        }

        return DB::transaction(function () use ($offering, $session, $data) {
            if ($session !== null) {
                $session->update($data);

                return $session;
            }

            $next = (int) $offering->sessions()->max('meeting_no') + 1;

            return $offering->sessions()->create([...$data, 'meeting_no' => $next]);
        });
    }

    /**
     * Only meetings without attendance can be deleted, so records are never lost.
     */
    public function delete(ClassSession $session): void
    {
        $session->loadMissing('courseOffering.semester')->courseOffering->semester->assertOpen();

        if ($session->attendance_taken_at !== null) {
            throw ValidationException::withMessages([
                'meeting' => __('Attendance was taken for this meeting; it can be edited but not deleted.'),
            ]);
        }

        $session->delete();
    }

    /**
     * Save attendance for every mahasantri enrolled in the offering.
     *
     * @param  array<int|string, string>  $statuses  course_enrollment_id => status
     * @param  array<int|string, string|null>  $notes  course_enrollment_id => note
     */
    public function recordAttendance(ClassSession $session, array $statuses, array $notes = []): void
    {
        $offering = $session->loadMissing('courseOffering.semester')->courseOffering;
        $offering->semester->assertOpen();

        $enrollmentIds = $offering->enrollments()->pluck('id')->all();
        $valid = array_column(AttendanceStatus::cases(), 'value');

        foreach ($enrollmentIds as $id) {
            if (! in_array($statuses[$id] ?? null, $valid, true)) {
                throw ValidationException::withMessages([
                    "statuses.{$id}" => __('Choose hadir, izin, sakit or alpa for every mahasantri.'),
                ]);
            }
        }

        DB::transaction(function () use ($session, $statuses, $notes, $enrollmentIds) {
            foreach ($enrollmentIds as $id) {
                $session->attendances()->updateOrCreate(
                    ['course_enrollment_id' => $id],
                    ['status' => $statuses[$id], 'note' => filled($notes[$id] ?? null) ? mb_substr((string) $notes[$id], 0, 255) : null],
                );
            }

            $session->update(['attendance_taken_at' => $session->attendance_taken_at ?? now()]);
        });
    }
}
