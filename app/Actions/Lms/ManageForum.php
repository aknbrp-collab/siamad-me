<?php

namespace App\Actions\Lms;

use App\Models\CourseOffering;
use App\Models\ForumPost;
use App\Models\ForumThread;
use App\Models\User;
use App\Support\OfferingAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Class discussion: the ustadz and enrolled mahasantri post; the ustadz
 * moderates (pin, lock, remove). Read-only once the semester is closed.
 */
class ManageForum
{
    public function startThread(CourseOffering $offering, User $user, string $title, string $body): ForumThread
    {
        $this->ensureParticipant($offering, $user);

        $title = trim($title);
        $body = trim($body);

        if ($title === '' || mb_strlen($title) > 255) {
            $this->fail('title', __('Give the topic a title (max 255 characters).'));
        }

        if ($body === '' || mb_strlen($body) > 10000) {
            $this->fail('body', __('Write a message (max 10,000 characters).'));
        }

        return $offering->forumThreads()->create([
            'user_id' => $user->id,
            'title' => $title,
            'body' => $body,
            'last_posted_at' => now(),
        ]);
    }

    public function reply(ForumThread $thread, User $user, string $body): ForumPost
    {
        $offering = $thread->loadMissing('courseOffering')->courseOffering;
        $this->ensureParticipant($offering, $user);

        if ($thread->is_locked && ! OfferingAccess::teaches($user, $offering)) {
            $this->fail('reply', __('This topic is locked.'));
        }

        $body = trim($body);

        if ($body === '' || mb_strlen($body) > 10000) {
            $this->fail('reply', __('Write a message (max 10,000 characters).'));
        }

        return DB::transaction(function () use ($thread, $user, $body) {
            $post = $thread->posts()->create(['user_id' => $user->id, 'body' => $body]);
            $thread->update(['last_posted_at' => now()]);

            return $post;
        });
    }

    public function togglePin(ForumThread $thread, User $user): void
    {
        $this->ensureModerator($thread, $user);
        $thread->update(['is_pinned' => ! $thread->is_pinned]);
    }

    public function toggleLock(ForumThread $thread, User $user): void
    {
        $this->ensureModerator($thread, $user);
        $thread->update(['is_locked' => ! $thread->is_locked]);
    }

    /**
     * Authors remove their own posts; the ustadz can remove any.
     */
    public function deletePost(ForumPost $post, User $user): void
    {
        $thread = $post->loadMissing('thread.courseOffering')->thread;
        $this->ensureParticipant($thread->courseOffering, $user);

        if ($post->user_id !== $user->id && ! OfferingAccess::teaches($user, $thread->courseOffering)) {
            $this->fail('reply', __('You can only remove your own messages.'));
        }

        $post->delete();
    }

    public function deleteThread(ForumThread $thread, User $user): void
    {
        $this->ensureParticipant($thread->loadMissing('courseOffering')->courseOffering, $user);

        if ($thread->user_id !== $user->id && ! OfferingAccess::teaches($user, $thread->courseOffering)) {
            $this->fail('reply', __('You can only remove your own topics.'));
        }

        $thread->delete();
    }

    private function ensureModerator(ForumThread $thread, User $user): void
    {
        $offering = $thread->loadMissing('courseOffering')->courseOffering;
        $this->ensureParticipant($offering, $user);

        if (! OfferingAccess::teaches($user, $offering)) {
            $this->fail('reply', __('Only the ustadz can moderate the forum.'));
        }
    }

    private function ensureParticipant(CourseOffering $offering, User $user): void
    {
        $offering->loadMissing('semester')->semester->assertOpen();

        abort_unless(OfferingAccess::participates($user, $offering), 403);
    }

    private function fail(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
