<?php

namespace App\Listeners;

use App\Events\ArticlePublished;
use App\Jobs\Cms\PushArticle;
use App\Support\Cms\CmsClient;

/**
 * Every publication of an article is sent to the CMS (v5 D31), once the
 * database transaction is committed.
 */
class SendPublishedArticleToCms
{
    public function handle(ArticlePublished $event): void
    {
        if (CmsClient::enabled()) {
            PushArticle::dispatch($event->article->id)->afterCommit();
        }
    }
}
