<?php

namespace App\Support\Forum;

use App\Models\CourseOffering;
use App\Models\ForumThread;
use App\Models\User;
use App\Support\OfferingAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * A class forum: the offering's muhadhir moderates, enrolled mahasantri take
 * part; read-only once the semester is closed.
 */
final readonly class OfferingForum implements ForumContext
{
    public function __construct(public CourseOffering $offering) {}

    public function participates(?User $user): bool
    {
        return OfferingAccess::participates($user, $this->offering);
    }

    public function moderates(?User $user): bool
    {
        return OfferingAccess::teaches($user, $this->offering);
    }

    public function isReadOnly(): bool
    {
        return $this->offering->loadMissing('semester')->semester->isLocked();
    }

    public function assertWritable(): void
    {
        $this->offering->loadMissing('semester')->semester->assertOpen();
    }

    public function threads(): Builder
    {
        return ForumThread::query()->where('course_offering_id', $this->offering->id);
    }

    public function participants(): Collection
    {
        $offering = $this->offering->loadMissing(['teacher.user', 'enrollments.student.user']);

        return Collection::make([$offering->teacher->user])
            ->merge($offering->enrollments->map(fn ($enrollment) => $enrollment->student->user));
    }

    public function name(): string
    {
        return $this->offering->subject_name;
    }

    public function threadUrl(ForumThread $thread, User $user): string
    {
        return $this->moderates($user)
            ? route('ustadz.offerings.forum', [$this->offering, 'thread' => $thread->id])
            : route('mahasantri.class', [$this->offering, 'tab' => 'forum', 'thread' => $thread->id]);
    }

    public function threadAttributes(): array
    {
        return ['course_offering_id' => $this->offering->id];
    }
}
