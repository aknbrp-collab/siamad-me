<?php

namespace App\Jobs\Cms;

use App\Models\Article;
use App\Support\Cms\CmsClient;
use Illuminate\Http\Client\Response;

/**
 * Take an article off the website (spec §3.3). Unknown to the CMS (404) means
 * there is nothing to take down.
 */
class WithdrawArticle extends CmsArticleJob
{
    protected function call(CmsClient $client, Article $article): Response
    {
        return $client->send('DELETE', 'articles/'.rawurlencode((string) $article->code), null, $article->code);
    }

    protected function handled(Article $article, Response $response): void
    {
        if ($response->successful() || $response->status() === 404) {
            $article->forceFill([
                'cms_status' => $response->successful() ? 'withdrawn' : null,
                'cms_public_url' => null,
                'cms_changes_pending' => false,
                'cms_error' => null,
                'cms_checked_at' => now(),
            ])->save();

            return;
        }

        $this->refused($article, $response);
    }
}
