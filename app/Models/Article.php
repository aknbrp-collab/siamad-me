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
 * @property string|null $image_path The picture (v7 D48), on the private disk
 * @property string|null $image_caption
 * @property ArticleStatus $status
 * @property int $review_round
 * @property Carbon|null $submitted_at
 * @property Carbon|null $published_at First publication
 * @property Carbon|null $withdrawn_at
 * @property string|null $cms_id
 * @property string|null $cms_status draft | under_review | approved | rejected | withdrawn (as the CMS reports it)
 * @property string|null $cms_review_note
 * @property string|null $cms_public_url
 * @property bool $cms_changes_pending
 * @property int|null $cms_sent_version
 * @property Carbon|null $cms_sent_at
 * @property Carbon|null $cms_checked_at
 * @property string|null $cms_error
 * @property Carbon $updated_at
 * @property-read User $author
 */
#[Fillable(['code', 'user_id', 'title', 'summary', 'body_md', 'keywords', 'image_path', 'image_caption', 'status', 'review_round', 'submitted_at', 'published_at', 'withdrawn_at',
    'cms_id', 'cms_status', 'cms_review_note', 'cms_public_url', 'cms_changes_pending', 'cms_sent_version', 'cms_sent_at', 'cms_checked_at', 'cms_error'])]
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
            'cms_changes_pending' => 'boolean',
            'cms_sent_version' => 'integer',
            'cms_sent_at' => 'datetime',
            'cms_checked_at' => 'datetime',
        ];
    }

    /**
     * The body as safe HTML: raw HTML in the Markdown is removed and unsafe links are dropped.
     */
    public static function render(string $markdown): string
    {
        return Str::markdown($markdown, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
    }

    /**
     * What the website editors' state means for the author.
     */
    public function cmsStatusLabel(): ?string
    {
        $label = match ($this->cms_status) {
            'draft' => __('Received by the website editors'),
            'under_review' => __('Being checked by the website editors'),
            'approved' => __('On the website'),
            'rejected' => __('Not accepted by the website editors'),
            'withdrawn' => __('Removed from the website'),
            default => null,
        };

        return is_string($label) ? $label : null;
    }

    /**
     * The version that is published now (the latest one).
     */
    public function latestVersion(): ?ArticleVersion
    {
        return $this->versions()->orderByDesc('version')->first();
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
