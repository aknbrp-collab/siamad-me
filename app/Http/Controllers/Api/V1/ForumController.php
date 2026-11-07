<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Lms\ManageForum;
use App\Http\Controllers\Controller;
use App\Models\CourseOffering;
use App\Models\ForumPost;
use App\Models\ForumThread;
use App\Models\Teacher;
use App\Support\Forum\ForumContext;
use App\Support\Forum\OfferingForum;
use App\Support\Forum\SupervisionForum;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Forums: the forum of a class kuliah (`class`, id = class id) and the forum
 * of a pembimbing akademik with their supervisees (`supervision`, id = the
 * muhadhir's teacher id). Mahasantri and muhadhir; the muhadhir pins, locks
 * and removes.
 */
#[Group('Forum', weight: 8)]
class ForumController extends Controller
{
    /**
     * Threads.
     *
     * Pinned first, then by the latest post.
     */
    public function threads(Request $request, string $scope, int $id): JsonResponse
    {
        $context = $this->context($request, $scope, $id);

        return response()->json(['data' => [
            'name' => $context->name(),
            'moderator' => $context->moderates($request->user()),
            'read_only' => $context->isReadOnly(),
            'threads' => $context->threads()->with('user')->withCount('posts')->orderByDesc('is_pinned')->orderByDesc('last_posted_at')->get()
                ->map(fn (ForumThread $thread) => $this->thread($thread) + ['posts_count' => $thread->posts_count])->values()->all(),
        ]]);
    }

    /**
     * A thread with its replies.
     */
    public function show(Request $request, string $scope, int $id, int $thread): JsonResponse
    {
        $item = $this->context($request, $scope, $id)->threads()->with(['user', 'posts.user'])->findOrFail($thread);

        return response()->json(['data' => [
            ...$this->thread($item),
            'posts' => $item->posts->map(fn (ForumPost $post) => [
                'id' => $post->id,
                'body' => $post->body,
                'author' => $post->user->name,
                'mine' => $post->user_id === $request->user()->id,
                'created_at' => $post->created_at->toIso8601String(),
            ])->values()->all(),
        ]]);
    }

    /**
     * Start a thread.
     */
    public function store(Request $request, string $scope, int $id, ManageForum $forum): JsonResponse
    {
        $request->validate(['title' => ['required', 'string', 'max:255'], 'body' => ['required', 'string']]);
        $context = $this->context($request, $scope, $id);

        $thread = $forum->startThread($context, $request->user(), (string) $request->input('title'), (string) $request->input('body'));

        return response()->json(['data' => $this->thread($thread->load('user'))], 201);
    }

    /**
     * Reply.
     */
    public function reply(Request $request, string $scope, int $id, int $thread, ManageForum $forum): Response
    {
        $request->validate(['body' => ['required', 'string']]);
        $forum->reply($this->context($request, $scope, $id)->threads()->findOrFail($thread), $request->user(), (string) $request->input('body'));

        return response()->noContent();
    }

    /**
     * Pin or unpin (muhadhir).
     */
    public function pin(Request $request, string $scope, int $id, int $thread, ManageForum $forum): Response
    {
        $forum->togglePin($this->context($request, $scope, $id)->threads()->findOrFail($thread), $request->user());

        return response()->noContent();
    }

    /**
     * Lock or unlock (muhadhir).
     */
    public function lock(Request $request, string $scope, int $id, int $thread, ManageForum $forum): Response
    {
        $forum->toggleLock($this->context($request, $scope, $id)->threads()->findOrFail($thread), $request->user());

        return response()->noContent();
    }

    /**
     * Delete a thread.
     *
     * Its author while it has no replies, or the muhadhir.
     */
    public function destroy(Request $request, string $scope, int $id, int $thread, ManageForum $forum): Response
    {
        $forum->deleteThread($this->context($request, $scope, $id)->threads()->findOrFail($thread), $request->user());

        return response()->noContent();
    }

    /**
     * Delete a reply.
     *
     * Its author, or the muhadhir.
     */
    public function destroyPost(Request $request, string $scope, int $id, int $post, ManageForum $forum): Response
    {
        $context = $this->context($request, $scope, $id);
        $item = ForumPost::query()->whereIn('forum_thread_id', $context->threads()->select('id'))->findOrFail($post);
        $forum->deletePost($item, $request->user());

        return response()->noContent();
    }

    private function context(Request $request, string $scope, int $id): ForumContext
    {
        $context = match ($scope) {
            'class' => new OfferingForum(CourseOffering::query()->with('semester')->findOrFail($id)),
            'supervision' => new SupervisionForum(Teacher::query()->findOrFail($id)),
            default => abort(404),
        };
        abort_unless($context->participates($request->user()), 403);

        return $context;
    }

    /**
     * @return array<string, mixed>
     */
    private function thread(ForumThread $thread): array
    {
        return [
            'id' => $thread->id,
            'title' => $thread->title,
            'body' => $thread->body,
            'author' => $thread->user->name,
            'is_pinned' => $thread->is_pinned,
            'is_locked' => $thread->is_locked,
            'last_posted_at' => $thread->last_posted_at->toIso8601String(),
            'created_at' => $thread->created_at->toIso8601String(),
        ];
    }
}
