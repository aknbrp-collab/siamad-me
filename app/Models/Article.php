<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Enums\ArticleStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A simple article for the public website (v5 D29). The columns hold the
 * working copy; every publication is kept in article_versions.
 *
 * @property int $id
 * @property string|null $code Stable id for the CMS, given on the first publication
 * @property int $user_id
 * @property string $title
 * @property string|null $summary
 * @property string $body_md Markdown
 * @property list<string>|null $keywords
 * @property ArticleStatus $status
 * @property int $review_round
 * @property Carbon|null $submitted_at
 * @property Carbon|null $published_at First publication
 * @property Carbon|null $withdrawn_at
 * @property Carbon $updated_at
 * @property-read User $author
 */
#[Fillable(['code', 'user_id', 'title', 'summary', 'body_md', 'keywords', 'status', 'review_round', 'submitted_at', 'published_at', 'withdrawn_at'])]
class Article extends Model
{
    use Auditable;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
        'review_round' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'keywords' => 'array',
            'status' => ArticleStatus::class,
            'review_round' => 'integer',
            'submitted_at' => 'datetime',
            'published_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    /**
     * The body as safe HTML: raw HTML in the Markdown is removed and unsafe links are dropped.
     */
    public static function render(string $markdown): string
    {
        return Str::markdown($markdown, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
    }

    public function bodyHtml(): string
    {
        return self::render($this->body_md);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return HasMany<ArticleReview, $this>
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(ArticleReview::class);
    }

    /**
     * @return HasMany<ArticleVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(ArticleVersion::class);
    }
}
