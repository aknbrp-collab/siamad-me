<?php

namespace App\Support\Forum;

use App\Models\ForumThread;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Where a forum lives and who may use it: a class (course offering) or a
 * pembimbing akademik's supervision group.
 */
interface ForumContext
{
    /** May read and post. */
    public function participates(?User $user): bool;

    /** May pin, lock and remove anyone's posts. */
    public function moderates(?User $user): bool;

    /** No new posts (e.g. the class's semester is closed). */
    public function isReadOnly(): bool;

    /** Throws a validation error when the forum is read-only. */
    public function assertWritable(): void;

    /**
     * @return Builder<ForumThread>
     */
    public function threads(): Builder;

    /**
     * Columns tying a new thread to this forum.
     *
     * @return array<string, int>
     */
    public function threadAttributes(): array;
}
