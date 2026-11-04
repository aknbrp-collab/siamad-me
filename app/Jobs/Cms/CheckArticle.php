<?php

namespace App\Jobs\Cms;

use App\Models\Article;
use App\Support\Cms\CmsClient;
use Illuminate\Http\Client\Response;

/**
 * Read the review status, the reviewer's note and the website link (spec §3.2).
 */
class CheckArticle extends CmsArticleJob
{
    protected function call(CmsClient $client, Article $article): Response
    {
        return $client->send('GET', 'articles/'.rawurlencode((string) $article->code), null, $article->code);
    }

    protected function handled(Article $article, Response $response): void
    {
        if (! $response->successful()) {
            $this->refused($article, $response);

            return;
        }

        $this->remember($article, $response);
        $article->forceFill([
            'cms_review_note' => $response->json('review_note'),
            'cms_public_url' => $response->json('public_url'),
            'cms_changes_pending' => (bool) $response->json('changes_pending_review', false),
        ])->save();
    }
}
