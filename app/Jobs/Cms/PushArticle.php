<?php

namespace App\Jobs\Cms;

use App\Models\Article;
use App\Support\Cms\CmsClient;
use Illuminate\Http\Client\Response;

/**
 * Send the published version of an article to the CMS (spec §3.1). Idempotent
 * on the article code: the CMS updates what it has.
 */
class PushArticle extends CmsArticleJob
{
    protected function call(CmsClient $client, Article $article): Response
    {
        return $client->send('POST', 'articles', self::payload($article), $article->code);
    }

    protected function handled(Article $article, Response $response): void
    {
        if (! $response->successful()) {
            $this->refused($article, $response);

            return;
        }

        $this->remember($article, $response);
        $article->forceFill(['cms_sent_version' => $article->latestVersion()?->version, 'cms_sent_at' => now()])->save();
    }

    /**
     * The §3.1 body, from the latest published version.
     *
     * @return array<string, mixed>
     */
    public static function payload(Article $article): array
    {
        $version = $article->latestVersion();
        $author = $article->author()->with('teacher')->firstOrFail();

        return array_filter([
            'id' => $article->code,
            'type' => config('services.cms.article_type'),
            'title' => $version->title ?? $article->title,
            'authors' => [$author->teacher?->displayName() ?? $author->name],
            'year' => ($article->published_at ?? now())->year,
            'abstract' => $version->summary ?? $article->summary,
            'body_html' => Article::render($version->body_md ?? $article->body_md),
            'keywords' => $version->keywords ?? $article->keywords,
        ], fn ($value) => $value !== null && $value !== [] && $value !== '');
    }
}
