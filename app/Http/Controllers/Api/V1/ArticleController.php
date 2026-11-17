<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Articles\ManageArticles;
use App\Enums\ArticleStatus;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Jobs\Cms\CheckArticle;
use App\Jobs\Cms\PushArticle;
use App\Models\Article;
use App\Models\ArticleReview;
use App\Models\User;
use App\Support\ActiveRole;
use App\Support\ApiFiles;
use App\Support\Cms\CmsClient;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Articles (v5 D29): written in Markdown by mahasantri and muhadhir. A
 * mahasantri's article needs two muhadhir approvals; a muhadhir publishes
 * directly. Published articles go to the website CMS (D31).
 */
#[Group('Articles', weight: 13)]
class ArticleController extends Controller
{
    /**
     * My articles.
     */
    public function mine(Request $request): JsonResponse
    {
        abort_unless(ManageArticles::canWrite($request->user()), 403);

        return response()->json(['data' => Article::query()->where('user_id', $request->user()->id)->latest('updated_at')->get()
            ->map(fn (Article $article) => $this->summary($article))->values()->all()]);
    }

    /**
     * Articles to review (muhadhir).
     *
     * Mahasantri articles in review, oldest first, with whether I already gave my decision this round.
     */
    public function reviewQueue(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless(ManageArticles::isMuhadhir($user), 403);

        $articles = Article::query()->with(['author', 'reviews'])
            ->where('status', ArticleStatus::InReview)->where('user_id', '!=', $user->id)
            ->orderBy('submitted_at')->get();

        return response()->json(['data' => $articles->map(fn (Article $article) => [
            ...$this->summary($article),
            'author' => $article->author->name,
            'approvals' => $article->reviews->where('round', $article->review_round)->where('decision', 'approved')->count(),
            'reviewed_by_me' => $article->reviews->where('round', $article->review_round)->contains('reviewer_id', $user->id),
        ])->values()->all()]);
    }

    /**
     * An article.
     *
     * The working copy (Markdown and rendered HTML), the reviews, the website status (author and staff) and
     * what I can do with it now.
     */
    public function show(Request $request, Article $article): JsonResponse
    {
        $user = $request->user();
        abort_unless(ManageArticles::canView($user, $article), 403);

        return response()->json(['data' => $this->detail($user, $article)]);
    }

    /**
     * Write an article.
     *
     * `keywords`: comma separated. Saved as a draft.
     */
    public function store(Request $request, ManageArticles $manage): JsonResponse
    {
        $request->validate($this->rules());
        $article = $manage->save(null, $request->user(), $request->only(['title', 'summary', 'body', 'keywords']));

        return response()->json(['data' => $this->detail($request->user(), $article)], 201);
    }

    /**
     * Change an article.
     *
     * Not while it is in review. A changed published article becomes a draft until it is published again.
     */
    public function update(Request $request, Article $article, ManageArticles $manage): JsonResponse
    {
        $request->validate([...$this->rules(), 'image_caption' => ['nullable', 'string', 'max:255']]);
        $manage->save($article, $request->user(), [
            ...$request->only(['title', 'summary', 'body', 'keywords']),
            // The picture stays; its caption changes only when sent.
            'image_caption' => $request->exists('image_caption') ? $request->input('image_caption') : $article->image_caption,
        ]);

        return response()->json(['data' => $this->detail($request->user(), $article->refresh())]);
    }

    /**
     * Set the picture.
     *
     * Multipart `image` (JPG, PNG or WebP; made at most 1600 px wide) and an optional `image_caption`.
     * Like any change, a published article becomes a draft until it is published again (v7 D48).
     */
    public function image(Request $request, Article $article, ManageArticles $manage): JsonResponse
    {
        $request->validate(['image' => ['required', 'file'], 'image_caption' => ['nullable', 'string', 'max:255']]);
        $manage->save($article, $request->user(), [
            ...$this->current($article),
            'image' => $request->file('image'),
            'image_caption' => $request->input('image_caption'),
        ]);

        return response()->json(['data' => $this->detail($request->user(), $article->refresh())]);
    }

    /**
     * Remove the picture.
     */
    public function removeImage(Request $request, Article $article, ManageArticles $manage): JsonResponse
    {
        $manage->save($article, $request->user(), [...$this->current($article), 'remove_image' => true]);

        return response()->json(['data' => $this->detail($request->user(), $article->refresh())]);
    }

    /**
     * The working copy as ManageArticles::save() takes it.
     *
     * @return array<string, string|null>
     */
    private function current(Article $article): array
    {
        return [
            'title' => $article->title,
            'summary' => $article->summary,
            'body' => $article->body_md,
            'keywords' => implode(', ', $article->keywords ?? []),
        ];
    }

    /**
     * Send for review (mahasantri).
     */
    public function submit(Request $request, Article $article, ManageArticles $manage): JsonResponse
    {
        $manage->submit($article, $request->user());

        return response()->json(['data' => $this->detail($request->user(), $article->refresh())]);
    }

    /**
     * Publish (muhadhir, their own article).
     */
    public function publish(Request $request, Article $article, ManageArticles $manage): JsonResponse
    {
        $manage->publish($article, $request->user());

        return response()->json(['data' => $this->detail($request->user(), $article->refresh())]);
    }

    /**
     * Review (muhadhir).
     *
     * `approve: true` approves (the second approval publishes); `false` asks for changes, with a `note`.
     */
    public function review(Request $request, Article $article, ManageArticles $manage): JsonResponse
    {
        $request->validate(['approve' => ['required', 'boolean'], 'note' => ['nullable', 'string', 'max:5000']]);
        $manage->review($article, $request->user(), $request->boolean('approve'), $request->input('note'));

        return response()->json(['data' => $this->detail($request->user(), $article->refresh())]);
    }

    /**
     * Withdraw (author or staff).
     *
     * Takes the article off SIAKAD's published list and off the website.
     */
    public function withdraw(Request $request, Article $article, ManageArticles $manage): JsonResponse
    {
        $manage->withdraw($article, $request->user());

        return response()->json(['data' => $this->detail($request->user(), $article->refresh())]);
    }

    /**
     * Delete a draft that was never published.
     */
    public function discard(Request $request, Article $article, ManageArticles $manage): Response
    {
        $manage->discard($article, $request->user());

        return response()->noContent();
    }

    /**
     * Send to the website again.
     *
     * Queues the published version for the CMS (author or staff).
     */
    public function resend(Request $request, Article $article): Response
    {
        abort_unless($this->seesWebsite($request->user(), $article) && CmsClient::enabled() && $article->status === ArticleStatus::Published, 403);
        PushArticle::dispatch($article->id);

        return response()->noContent();
    }

    /**
     * Check the website status.
     *
     * Asks the CMS for the article's status; the answer shows in `website` a minute or two later.
     */
    public function check(Request $request, Article $article): Response
    {
        abort_unless($this->seesWebsite($request->user(), $article) && CmsClient::enabled() && $article->cms_sent_at !== null, 403);
        CheckArticle::dispatch($article->id);

        return response()->noContent();
    }

    /**
     * @return array<string, list<string>>
     */
    private function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'summary' => ['nullable', 'string', 'max:2000'],
            /** Markdown: headings, lists, quotes, links. */
            'body' => ['required', 'string'],
            'keywords' => ['nullable', 'string', 'max:500'],
        ];
    }

    private function seesWebsite(User $user, Article $article): bool
    {
        return $article->published_at !== null
            && ($article->user_id === $user->id || $user->hasRole(Role::Admin, Role::Superadmin, Role::AcademicMonitor));
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Article $article): array
    {
        return [
            'id' => $article->id,
            'code' => $article->code,
            'title' => $article->title,
            'status' => $article->status->value,
            'status_label' => $article->status->label(),
            'submitted_at' => $article->submitted_at?->toIso8601String(),
            'published_at' => $article->published_at?->toIso8601String(),
            'updated_at' => $article->updated_at->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(User $user, Article $article): array
    {
        $article->loadMissing('author');
        $reviews = $article->reviews()->with('reviewer')->orderByDesc('round')->orderBy('id')->get();
        $mine = $article->user_id === $user->id;
        $muhadhir = ManageArticles::isMuhadhir($user);
        $editable = $mine && $article->status !== ArticleStatus::InReview;

        return [
            ...$this->summary($article),
            'author' => $article->author->name,
            'mine' => $mine,
            'summary' => $article->summary,
            'body' => $article->body_md,
            'body_html' => $article->bodyHtml(),
            'keywords' => $article->keywords ?? [],
            /** A signed link to the picture (v7 D48), or null. */
            'image_url' => $article->image_path === null ? null : ApiFiles::url($user, ActiveRole::get(), 'article-image', $article->id),
            'image_caption' => $article->image_caption,
            'review_round' => $article->review_round,
            'reviews' => $reviews->map(fn (ArticleReview $review) => [
                'round' => $review->round,
                'reviewer' => $review->reviewer->name,
                /** approved | changes */
                'decision' => $review->decision,
                'note' => $review->note,
                'created_at' => $review->created_at->toIso8601String(),
            ])->values()->all(),
            'website' => ! $this->seesWebsite($user, $article) ? null : [
                'enabled' => CmsClient::enabled(),
                'status' => $article->cms_status,
                'status_label' => $article->cmsStatusLabel(),
                'review_note' => $article->cms_review_note,
                'public_url' => $article->cms_public_url,
                'changes_pending' => $article->cms_changes_pending,
                'sent_at' => $article->cms_sent_at?->toIso8601String(),
                'checked_at' => $article->cms_checked_at?->toIso8601String(),
                'error' => $article->cms_error,
            ],
            /** What I can do now. */
            'can' => [
                'edit' => $editable,
                'submit' => $editable && ! ManageArticles::isMuhadhir($article->author) && in_array($article->status, [ArticleStatus::Draft, ArticleStatus::ChangesRequested], true),
                'publish' => $editable && $muhadhir && $article->status !== ArticleStatus::Published,
                'review' => $article->status === ArticleStatus::InReview && ! $mine && $muhadhir
                    && ! $reviews->where('round', $article->review_round)->contains('reviewer_id', $user->id),
                'withdraw' => $article->published_at !== null && $article->status !== ArticleStatus::Withdrawn
                    && ($mine || $user->hasRole(Role::Admin, Role::Superadmin, Role::AcademicMonitor)),
                'discard' => $mine && $article->status === ArticleStatus::Draft && $article->published_at === null && $article->review_round === 0,
            ],
        ];
    }
}
