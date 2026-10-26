<?php

namespace App\Actions\Lms;

use App\Models\CourseOffering;
use App\Models\ForumPost;
use App\Models\ForumThread;
use App\Models\User;
use App\Support\Forum\ForumContext;
use App\Support\Forum\OfferingForum;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Forum discussion, for a class (muhadhir and enrolled mahasantri) or a
 * pembimbing akademik's supervision group (supervisor and supervisees). The
 * muhadhir of the forum moderates (pin, lock, remove). A class forum is
 * read-only once its semester is closed.
 */
class ManageForum
{
    public function startThread(CourseOffering|ForumContext $forum, User $user, string $title, string $body): ForumThread
    {
        $context = $forum instanceof CourseOffering ? new OfferingForum($forum) : $forum;
        $this->ensureParticipant($context, $user);

        $title = trim($title);
        $body = trim($body);

        if ($title === '' || mb_strlen($title) > 255) {
            $this->fail('title', __('Give the topic a title (max 255 characters).'));
        }

        if ($body === '' || mb_strlen($body) > 10000) {
            $this->fail('body', __('Write a message (max 10,000 characters).'));
        }

        return ForumThread::create([
            ...$context->threadAttributes(),
            'user_id' => $user->id,
            'title' => $title,
            'body' => $body,
            'last_posted_at' => now(),
        ]);
    }

    public function reply(ForumThread $thread, User $user, string $body): ForumPost
    {
        $context = $thread->context();
        $this->ensureParticipant($context, $user);

        if ($thread->is_locked && ! $context->moderates($user)) {
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
     * Authors remove their own posts; the forum's muhadhir can remove any.
     */
    public function deletePost(ForumPost $post, User $user): void
    {
        $context = $post->loadMissing('thread')->thread->context();
        $this->ensureParticipant($context, $user);

        if ($post->user_id !== $user->id && ! $context->moderates($user)) {
            $this->fail('reply', __('You can only remove your own messages.'));
        }

        $post->delete();
    }

    public function deleteThread(ForumThread $thread, User $user): void
    {
        $context = $thread->context();
        $this->ensureParticipant($context, $user);

        if ($thread->user_id !== $user->id && ! $context->moderates($user)) {
            $this->fail('reply', __('You can only remove your own topics.'));
        }

        $thread->delete();
    }

    private function ensureModerator(ForumThread $thread, User $user): void
    {
        $context = $thread->context();
        $this->ensureParticipant($context, $user);

        if (! $context->moderates($user)) {
            $this->fail('reply', __('Only the ustadz can moderate the forum.'));
        }
    }

    private function ensureParticipant(ForumContext $context, User $user): void
    {
        $context->assertWritable();

        abort_unless($context->participates($user), 403);
    }

    private function fail(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
