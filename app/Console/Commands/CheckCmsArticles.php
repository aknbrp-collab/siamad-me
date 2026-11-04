<?php

namespace App\Console\Commands;

use App\Jobs\Cms\CheckArticle;
use App\Models\Article;
use App\Support\Cms\CmsClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Daily: ask the CMS for the review status and website link of every article
 * it has received (spec §3.2), so authors see whether it is online.
 */
#[Signature('siakad:cms-check')]
#[Description('Read the CMS review status of every article sent to the website')]
class CheckCmsArticles extends Command
{
    public function handle(): int
    {
        if (! CmsClient::enabled()) {
            $this->info('The CMS integration is off (CMS_WEBHOOK_URL / CMS_WEBHOOK_SECRET).');

            return self::SUCCESS;
        }

        $ids = Article::query()->whereNotNull('cms_sent_at')->pluck('id');
        $ids->each(fn (int $id) => CheckArticle::dispatch($id));

        $this->info("Queued {$ids->count()} status checks.");

        return self::SUCCESS;
    }
}
