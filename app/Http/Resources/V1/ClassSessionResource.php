<?php

namespace App\Http\Resources\V1;

use App\Models\ClassSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A meeting (pertemuan) of a class kuliah, with the mahasantri's own attendance
 * when the attendances are loaded for them.
 *
 * @mixin ClassSession
 */
class ClassSessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ClassSession $session */
        $session = $this->resource;
        $attendance = $session->relationLoaded('attendances') ? $session->attendances->first() : null;

        return [
            'id' => $session->id,
            'meeting_no' => $session->meeting_no,
            'held_on' => $session->held_on->toDateString(),
            'topic' => $session->topic,
            'mode' => $session->mode->value,
            'meeting_provider' => $session->meeting_provider?->value,
            /** Online meetings: the link to join. */
            'meeting_url' => $session->meeting_url,
            'attendance_taken' => $session->attendance_taken_at !== null,
            /** hadir | izin | sakit | alpa, or null before attendance is taken. */
            'my_attendance' => $attendance?->status->value,
            'my_attendance_label' => $attendance?->status->label(),
        ];
    }
}
