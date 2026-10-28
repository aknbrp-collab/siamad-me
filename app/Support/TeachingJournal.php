<?php

namespace App\Support;

use App\Enums\AttendanceStatus;
use App\Models\ClassSession;
use App\Models\CourseOffering;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * The jurnal mengajar of a class or of classes taught together (D13, D14):
 * one row per meeting date, merging the meetings of the joined classes. The
 * journal fields are taken from whichever class's meeting of that date has
 * them, so the muhadhir fills them once.
 */
final class TeachingJournal
{
    /**
     * Each row: date, topic, material, method, note, present, total, mode.
     *
     * @param  EloquentCollection<int, CourseOffering>  $members
     * @return list<array<string, mixed>>
     */
    public static function rows(EloquentCollection $members): array
    {
        $sessions = ClassSession::query()
            ->whereIn('course_offering_id', $members->modelKeys())
            ->withCount([
                'attendances as present_count' => fn ($query) => $query->where('status', AttendanceStatus::Present),
                'attendances as total_count',
            ])
            ->orderBy('held_on')->orderBy('meeting_no')
            ->get();

        $first = fn (Collection $group, string $field) => $group->pluck($field)->first(fn ($value) => filled($value));

        return array_values($sessions->groupBy(fn (ClassSession $session) => $session->held_on->toDateString())
            ->map(fn (Collection $group) => [
                'date' => $group->first()->held_on,
                'topic' => $first($group, 'topic'),
                'material' => $first($group, 'material'),
                'method' => $first($group, 'method'),
                'note' => $first($group, 'journal_note'),
                'present' => (int) $group->sum('present_count'),
                'total' => (int) $group->sum('total_count'),
                'mode' => $group->first()->mode->label(),
            ])
            ->all());
    }
}
