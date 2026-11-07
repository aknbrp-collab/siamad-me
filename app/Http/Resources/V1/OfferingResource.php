<?php

namespace App\Http\Resources\V1;

use App\Models\CourseOffering;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A class kuliah: subject, class, muhadhir and time slot.
 *
 * @mixin CourseOffering
 */
class OfferingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CourseOffering $offering */
        $offering = $this->resource;

        return [
            'id' => $offering->id,
            'subject_code' => $offering->subject_code,
            'subject_name' => $offering->subject_name,
            'credits' => $offering->credits,
            'class_name' => $offering->class_name,
            'teacher' => $this->whenLoaded('teacher', fn () => $offering->teacher->user->name),
            /** 1 = Monday … 7 = Sunday; null when not scheduled. */
            'day_of_week' => $offering->day_of_week,
            'starts_at' => $offering->starts_at === null ? null : substr($offering->starts_at, 0, 5),
            'ends_at' => $offering->ends_at === null ? null : substr($offering->ends_at, 0, 5),
            'room' => $offering->room,
            'materials_count' => $this->whenCounted('materials'),
            'open_assignments_count' => $this->whenCounted('open_assignments'),
            'open_quizzes_count' => $this->whenCounted('open_quizzes'),
        ];
    }
}
