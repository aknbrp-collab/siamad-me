<?php

namespace App\Events;

use App\Models\Article;
use App\Models\ArticleVersion;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An article was published or republished (v5 D29). The CMS integration
 * (Step 5) sends it on.
 */
class ArticlePublished
{
    use Dispatchable;

    public function __construct(public Article $article, public ArticleVersion $version) {}
}
