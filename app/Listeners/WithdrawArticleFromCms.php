<?php

namespace App\Listeners;

use App\Events\ArticleWithdrawn;
use App\Jobs\Cms\WithdrawArticle;
use App\Support\Cms\CmsClient;

/**
 * A withdrawn article is taken off the website (v5 D31).
 */
class WithdrawArticleFromCms
{
    public function handle(ArticleWithdrawn $event): void
    {
        if (CmsClient::enabled()) {
            WithdrawArticle::dispatch($event->article->id)->afterCommit();
        }
    }
}
