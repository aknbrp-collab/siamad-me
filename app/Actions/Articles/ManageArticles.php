<?php

namespace App\Actions\Articles;

use App\Enums\ArticleStatus;
use App\Enums\Role;
use App\Enums\StudentStatus;
use App\Enums\TeacherStatus;
use App\Events\ArticlePublished;
use App\Events\ArticleWithdrawn;
use App\Models\Article;
use App\Models\ArticleReview;
use App\Models\ArticleVersion;
use App\Models\User;
use App\Support\ArticleImages;
use App\Support\Notify;
use App\Support\Upload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Articles (v5 D29). Mahasantri write and send them for review; any muhadhir
 * reviews (approve, or ask for changes with a note); two approvals in one
 * round publish the article. Muhadhir publish their own directly. Editing a
 * published article makes a new working copy; the published text stays as it
 * is until the copy is published again.
 */
class ManageArticles
{
    public const APPROVALS_NEEDED = 2;

    public static function canWrite(User $user): bool
    {
        $user->loadMissing(['student', 'teacher']);

        return $user->is_active && (self::isMuhadhir($user) || ($user->student !== null && $user->student->status === StudentStatus::Active));
    }

    public static function isMuhadhir(User $user): bool
    {
        $user->loadMissing('teacher');

        return $user->teacher !== null && $user->teacher->status === TeacherStatus::Active;
    }

    /**
     * Its author, every muhadhir, and the staff who oversee; once published, everyone.
     */
    public static function canView(User $user, Article $article): bool
    {
        return $article->user_id === $user->id
            || self::isMuhadhir($user)
            || $user->hasRole(Role::Admin, Role::Superadmin, Role::AcademicMonitor)
            || ($article->status === ArticleStatus::Published && $user->is_active);
    }

    /**
     * Create or change the working copy.
     *
     * @param  array<string, mixed>  $input  title, summary, body, keywords (comma separated), and the optional
     *                                       picture (v7 D48): image (an upload), image_caption, remove_image
     */
    public function save(?Article $article, User $author, array $input): Article
    {
        if (! self::canWrite($author)) {
            $this->fail('title', __('Only active mahasantri and muhadhir write articles.'));
        }

        if ($article !== null && $article->user_id !== $author->id) {
            $this->fail('title', __('Only its author can change this article.'));
        }

        if ($article?->status === ArticleStatus::InReview) {
            $this->fail('title', __('This article is being reviewed; it can be changed after the review.'));
        }

        $data = Validator::make($input, [
            'title' => ['required', 'string', 'max:255'],
            'summary' => ['nullable', 'string', 'max:2000'],
            'body' => ['required', 'string', 'min:50', 'max:200000'],
            'keywords' => ['nullable', 'string', 'max:500'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', Upload::max()],
            'image_caption' => ['nullable', 'string', 'max:255'],
            'remove_image' => ['nullable', 'boolean'],
        ], [], [
            'title' => __('title'), 'summary' => __('summary'), 'body' => __('text'), 'keywords' => __('keywords'),
            'image' => __('picture'), 'image_caption' => __('caption'),
        ])->validate();

        $keywords = collect(explode(',', (string) ($data['keywords'] ?? '')))
            ->map(fn (string $keyword) => mb_substr(trim($keyword), 0, 100))
            ->filter()->unique()->take(10)->values()->all();

        $article ??= new Article(['user_id' => $author->id]);
        $oldImage = $article->image_path;
        $image = ($data['remove_image'] ?? false) ? null : $oldImage;

        if (($data['image'] ?? null) instanceof UploadedFile) {
            $image = ArticleImages::store($data['image']);
        }

        $article->fill([
            'title' => trim($data['title']),
            'summary' => filled($data['summary'] ?? null) ? trim($data['summary']) : null,
            'body_md' => trim($data['body']),
            'keywords' => $keywords === [] ? null : $keywords,
            'image_path' => $image,
            'image_caption' => $image !== null && filled($data['image_caption'] ?? null) ? trim($data['image_caption']) : null,
        ]);

        // A changed published or withdrawn article is a new draft until it is published again.
        if ($article->exists && in_array($article->status, [ArticleStatus::Published, ArticleStatus::Withdrawn], true) && $article->isDirty()) {
            $article->status = ArticleStatus::Draft;
        }

        $article->save();

        if ($oldImage !== $article->image_path) {
            ArticleImages::forget($oldImage);
        }

        return $article;
    }

    /**
     * A mahasantri sends the article for review (a new round).
     */
    public function submit(Article $article, User $author): void
    {
        $this->ensureAuthor($article, $author);

        if (self::isMuhadhir($author)) {
            $this->fail('article', __('Muhadhir publish their articles directly.'));
        }

        if (! in_array($article->status, [ArticleStatus::Draft, ArticleStatus::ChangesRequested], true)) {
            $this->fail('article', __('Only a draft or an article with requested changes can be sent for review.'));
        }

        $article->update(['status' => ArticleStatus::InReview, 'review_round' => $article->review_round + 1, 'submitted_at' => now()]);

        Notify::send(
            User::query()->whereHas('teacher', fn ($query) => $query->where('status', TeacherStatus::Active))->get(),
            'article',
            __('Articles wait for review'),
            __(':title by :name', ['title' => $article->title, 'name' => $author->name]),
            route('articles.review'),
            'document-magnifying-glass',
            $author,
            'articles:review',
            __(':count articles wait for review', ['count' => ':count']),
        );
    }

    /**
     * A muhadhir publishes their own article.
     */
    public function publish(Article $article, User $author): ArticleVersion
    {
        $this->ensureAuthor($article, $author);

        if (! self::isMuhadhir($author)) {
            $this->fail('article', __('A mahasantri article is published after two muhadhir approve it.'));
        }

        if ($article->status === ArticleStatus::Published) {
            $this->fail('article', __('This version is already published.'));
        }

        return $this->doPublish($article, $author);
    }

    /**
     * A muhadhir's decision in the current round. Two approvals publish it;
     * asking for changes sends it back to the author.
     */
    public function review(Article $article, User $reviewer, bool $approve, ?string $note): void
    {
        if (! self::isMuhadhir($reviewer) || $reviewer->id === $article->user_id) {
            $this->fail('review', __('Only another muhadhir can review this article.'));
        }

        if ($article->status !== ArticleStatus::InReview) {
            $this->fail('review', __('This article is not waiting for review.'));
        }

        $note = trim((string) $note);

        if (! $approve && mb_strlen($note) < 5) {
            $this->fail('reviewNote', __('Tell the author what to change (at least 5 characters).'));
        }

        DB::transaction(function () use ($article, $reviewer, $approve, $note) {
            // One decision per muhadhir per round; the lock keeps two approvals from publishing twice.
            $article = Article::query()->whereKey($article->id)->lockForUpdate()->firstOrFail();

            if ($article->status !== ArticleStatus::InReview) {
                $this->fail('review', __('This article is not waiting for review.'));
            }

            if ($article->reviews()->where('round', $article->review_round)->where('reviewer_id', $reviewer->id)->exists()) {
                $this->fail('review', __('You already reviewed this round.'));
            }

            ArticleReview::create([
                'article_id' => $article->id,
                'round' => $article->review_round,
                'reviewer_id' => $reviewer->id,
                'decision' => $approve ? 'approved' : 'changes',
                'note' => $note !== '' ? $note : null,
            ]);

            if (! $approve) {
                $article->update(['status' => ArticleStatus::ChangesRequested]);
                $this->tellAuthor($article, __('Changes requested: :title', ['title' => $article->title]), $note, $reviewer);

                return;
            }

            $approvals = $article->reviews()->where('round', $article->review_round)->where('decision', 'approved')->count();

            if ($approvals >= self::APPROVALS_NEEDED) {
                $this->doPublish($article, $reviewer);
            } else {
                $this->tellAuthor($article, __('Approved by :name: :title', ['name' => $reviewer->name, 'title' => $article->title]), __('One more approval publishes it.'), $reviewer);
            }
        });
    }

    /**
     * Take a published article down (its author, or staff who oversee).
     */
    public function withdraw(Article $article, User $by): void
    {
        if ($article->user_id !== $by->id && ! $by->hasRole(Role::Admin, Role::Superadmin, Role::AcademicMonitor)) {
            $this->fail('article', __('Only its author or the academic office can withdraw this article.'));
        }

        if ($article->published_at === null || $article->status === ArticleStatus::Withdrawn) {
            $this->fail('article', __('Only a published article can be withdrawn.'));
        }

        $article->update(['status' => ArticleStatus::Withdrawn, 'withdrawn_at' => now()]);
        ArticleWithdrawn::dispatch($article);

        if ($article->user_id !== $by->id) {
            $this->tellAuthor($article, __('Withdrawn: :title', ['title' => $article->title]), __('Withdrawn by :name.', ['name' => $by->name]), $by);
        }
    }

    /**
     * Discard a draft that was never sent or published.
     */
    public function discard(Article $article, User $author): void
    {
        $this->ensureAuthor($article, $author);

        if ($article->status !== ArticleStatus::Draft || $article->published_at !== null || $article->review_round > 0) {
            $this->fail('article', __('Only a draft that was never sent or published can be deleted.'));
        }

        $image = $article->image_path;
        $article->delete();
        ArticleImages::forget($image);
    }

    private function doPublish(Article $article, User $by): ArticleVersion
    {
        $version = DB::transaction(function () use ($article, $by) {
            $number = (int) $article->versions()->max('version') + 1;

            $article->update([
                'code' => $article->code ?? sprintf('ART-%d-%04d', now()->year, $article->id),
                'status' => ArticleStatus::Published,
                'published_at' => $article->published_at ?? now(),
                'withdrawn_at' => null,
            ]);

            return ArticleVersion::create([
                'article_id' => $article->id,
                'version' => $number,
                'title' => $article->title,
                'summary' => $article->summary,
                'body_md' => $article->body_md,
                'keywords' => $article->keywords,
                'image_path' => $article->image_path,
                'image_caption' => $article->image_caption,
                'published_by' => $by->id,
            ]);
        });

        ArticlePublished::dispatch($article, $version);

        if ($by->id !== $article->user_id) {
            $this->tellAuthor($article, __('Published: :title', ['title' => $article->title]), __('Two muhadhir approved it. It is sent to the website editors.'), $by);
        }

        return $version;
    }

    private function tellAuthor(Article $article, string $title, string $body, User $by): void
    {
        Notify::send([$article->author], 'article', $title, $body, route('articles.show', $article), 'document-text', $by);
    }

    private function ensureAuthor(Article $article, User $user): void
    {
        if ($article->user_id !== $user->id) {
            $this->fail('article', __('Only its author can do this.'));
        }
    }

    private function fail(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
