<?php

namespace App\Support;

use App\Models\CourseOffering;
use Illuminate\Database\Eloquent\Collection;

/**
 * Finds course offerings in a semester that overlap a weekly time slot and
 * share its muhadhir, class or room. Only slots with a day, a start and an
 * end are compared; rooms match case-insensitively.
 *
 * A clash with an offering of the same subject and muhadhir in another class
 * may be a joint class (kelas gabungan, v4 D21): the page asks instead of
 * refusing.
 */
final class ScheduleClashes
{
    /**
     * @param  array{day_of_week: int|null, starts_at: string|null, ends_at: string|null, teacher_id: int, class_group_id: int, room: string|null, subject_id?: int|null}  $slot
     * @return Collection<int, CourseOffering>
     */
    public static function for(int $semesterId, array $slot, ?int $ignoreId = null): Collection
    {
        if ($slot['day_of_week'] === null || $slot['starts_at'] === null || $slot['ends_at'] === null) {
            return new Collection;
        }

        $start = substr($slot['starts_at'], 0, 5);
        $end = substr($slot['ends_at'], 0, 5);
        $room = self::room($slot['room']);

        return CourseOffering::query()
            ->with(['classGroup', 'teacher.user'])
            ->where('semester_id', $semesterId)
            ->where('day_of_week', $slot['day_of_week'])
            ->whereNotNull('starts_at')
            ->whereNotNull('ends_at')
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->get()
            ->filter(fn (CourseOffering $other) => substr((string) $other->starts_at, 0, 5) < $end
                && $start < substr((string) $other->ends_at, 0, 5))
            ->filter(fn (CourseOffering $other) => $other->teacher_id === $slot['teacher_id']
                || $other->class_group_id === $slot['class_group_id']
                || ($room !== null && self::room($other->room) === $room))
            ->values();
    }

    /**
     * Could this clash be the same lesson taught to two classes together?
     *
     * @param  array{subject_id?: int|null, teacher_id: int, class_group_id: int}  $slot
     */
    public static function joinable(array $slot, CourseOffering $other): bool
    {
        return isset($slot['subject_id'])
            && $other->subject_id === $slot['subject_id']
            && $other->teacher_id === $slot['teacher_id']
            && $other->class_group_id !== $slot['class_group_id'];
    }

    /**
     * One readable line per clash, e.g. "Fiqh Ibadah (Semester I Putra), Sabtu 07:30–09:00: same muhadhir".
     *
     * @param  array{teacher_id: int, class_group_id: int, room: string|null}  $slot
     * @param  Collection<int, CourseOffering>  $clashes
     */
    public static function describe(array $slot, Collection $clashes): string
    {
        return $clashes->map(function (CourseOffering $other) use ($slot) {
            $shared = collect([
                $other->teacher_id === $slot['teacher_id'] ? __('same muhadhir') : null,
                $other->class_group_id === $slot['class_group_id'] ? __('same class') : null,
                self::room($slot['room']) !== null && self::room($other->room) === self::room($slot['room']) ? __('same room') : null,
            ])->filter()->implode(', ');

            return __(':subject (:class), :when: :shared', [
                'subject' => $other->subject_name,
                'class' => $other->classGroup->name,
                'when' => $other->scheduleLabel(),
                'shared' => $shared,
            ]);
        })->implode('; ');
    }

    private static function room(?string $room): ?string
    {
        $room = mb_strtolower(trim((string) $room));

        return $room === '' ? null : $room;
    }
}
