<?php

namespace App\Jobs\Cms;

use App\Models\Article;
use App\Support\Cms\CmsClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use RuntimeException;
use Throwable;

/**
 * A call to the CMS about one article (v5 D31). Retries follow spec §3.5:
 * 429 waits for Retry-After, 5xx and connection errors back off 1, 5, 15 and
 * 60 minutes (5 attempts in all); any other 4xx is not retried and is shown
 * to the author.
 */
abstract class CmsArticleJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public int $articleId) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(CmsClient $client): void
    {
        $article = Article::query()->find($this->articleId);

        if ($article === null || $article->code === null || ! CmsClient::enabled()) {
            return;
        }

        $response = $this->call($client, $article);

        if ($response->status() === 429) {
            $wait = (int) $response->header('Retry-After');
            $this->release($wait > 0 ? $wait : 60);

            return;
        }

        if ($response->serverError()) {
            throw new RuntimeException("CMS answered {$response->status()} for {$article->code}.");
        }

        $this->handled($article, $response);
    }

    /**
     * After the last attempt: keep the reason on the article.
     */
    public function failed(?Throwable $exception): void
    {
        Article::query()->whereKey($this->articleId)->update([
            'cms_error' => __('The website could not be reached after several attempts: :reason', ['reason' => $exception?->getMessage() ?? '—']),
        ]);
    }

    abstract protected function call(CmsClient $client, Article $article): Response;

    abstract protected function handled(Article $article, Response $response): void;

    /**
     * A refusal (4xx other than 429): recorded, not retried.
     */
    protected function refused(Article $article, Response $response): void
    {
        $errors = implode(' ', Arr::flatten((array) $response->json('errors', [])));

        $article->update([
            'cms_error' => trim(__('The website refused it (:status): :message', [
                'status' => $response->status(),
                'message' => (string) ($response->json('message') ?? $response->reason()),
            ]).' '.$errors),
        ]);
    }

    /**
     * Store what the CMS says about the article (§3.1 and §3.2 responses).
     */
    protected function remember(Article $article, Response $response): void
    {
        $article->forceFill(array_filter([
            'cms_id' => $response->json('cms_id'),
            'cms_status' => $response->json('review_status'),
        ], fn ($value) => $value !== null) + ['cms_error' => null, 'cms_checked_at' => now()])->save();
    }
}
