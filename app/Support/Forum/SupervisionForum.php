<?php

namespace App\Support\Forum;

use App\Models\ForumThread;
use App\Models\Supervision;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * A pembimbing akademik's group forum: the supervising muhadhir moderates;
 * the mahasantri they currently supervise take part. Not tied to a semester,
 * so it never becomes read-only.
 */
final readonly class SupervisionForum implements ForumContext
{
    public function __construct(public Teacher $supervisor) {}

    public function participates(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        if ($this->moderates($user)) {
            return true;
        }

        $studentId = $user->student()->value('id');

        return $studentId !== null && Supervision::query()->current()
            ->where('teacher_id', $this->supervisor->id)
            ->where('student_id', $studentId)
            ->exists();
    }

    public function moderates(?User $user): bool
    {
        return $user !== null && $this->supervisor->user_id === $user->id;
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function assertWritable(): void
    {
        //
    }

    public function threads(): Builder
    {
        return ForumThread::query()->where('supervisor_id', $this->supervisor->id);
    }

    public function participants(): Collection
    {
        $students = Supervision::query()->current()
            ->with('student.user')
            ->where('teacher_id', $this->supervisor->id)
            ->get()
            ->map(fn (Supervision $supervision) => $supervision->student->user);

        return Collection::make([$this->supervisor->loadMissing('user')->user])->merge($students);
    }

    public function name(): string
    {
        return __('the supervision group of :name', ['name' => $this->supervisor->loadMissing('user')->user->name]);
    }

    public function threadUrl(ForumThread $thread, User $user): string
    {
        return $this->moderates($user)
            ? route('ustadz.supervision.forum', ['thread' => $thread->id])
            : route('mahasantri.supervision', ['thread' => $thread->id]);
    }

    public function threadAttributes(): array
    {
        return ['supervisor_id' => $this->supervisor->id];
    }
}
