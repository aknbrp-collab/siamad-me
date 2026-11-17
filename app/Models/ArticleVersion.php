<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The text of an article as it was published (one row per publication).
 *
 * @property int $id
 * @property int $article_id
 * @property int $version
 * @property string $title
 * @property string|null $summary
 * @property string $body_md
 * @property list<string>|null $keywords
 * @property string|null $image_path
 * @property string|null $image_caption
 * @property int $published_by
 * @property Carbon $created_at
 * @property-read Article $article
 */
#[Fillable(['article_id', 'version', 'title', 'summary', 'body_md', 'keywords', 'image_path', 'image_caption', 'published_by'])]
class ArticleVersion extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['version' => 'integer', 'keywords' => 'array'];
    }

    /**
     * @return BelongsTo<Article, $this>
     */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }
}
