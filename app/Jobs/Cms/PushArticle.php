<?php

namespace App\Jobs\Cms;

use App\Models\Article;
use App\Support\ArticleImages;
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
        $image = ArticleImages::publicUrl($article);
        $body = Article::render($version->body_md ?? $article->body_md);

        // The picture also on top of the text (v7 D48), so the website shows it before it reads image_url.
        if ($image !== null) {
            $caption = $version?->image_caption;
            $body = '<figure><img src="'.e($image).'" alt="'.e($caption ?? $version->title ?? $article->title).'">'
                .($caption !== null ? '<figcaption>'.e($caption).'</figcaption>' : '').'</figure>'."\n".$body;
        }

        return array_filter([
            'id' => $article->code,
            'type' => config('services.cms.article_type'),
            'title' => $version->title ?? $article->title,
            'authors' => [$author->teacher?->displayName() ?? $author->name],
            'year' => ($article->published_at ?? now())->year,
            'abstract' => $version->summary ?? $article->summary,
            'body_html' => $body,
            'keywords' => $version->keywords ?? $article->keywords,
            'image_url' => $image,
            'image_caption' => $image !== null ? $version?->image_caption : null,
        ], fn ($value) => $value !== null && $value !== [] && $value !== '');
    }
}
