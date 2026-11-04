<?php

namespace App\Events;

use App\Models\Article;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A published article was withdrawn (v5 D29). The CMS integration (Step 5)
 * removes it from the website.
 */
class ArticleWithdrawn
{
    use Dispatchable;

    public function __construct(public Article $article) {}
}
