<?php

use App\Actions\Articles\ManageArticles;
use App\Enums\ArticleStatus;
use App\Models\Article;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Article review')] class extends Component {
    public function mount(): void
    {
        abort_unless(ManageArticles::isMuhadhir(auth()->user()), 403);
    }

    /**
     * Mahasantri articles waiting for review, oldest first, with whether I
     * already reviewed this round.
     *
     * @return Collection<int, Article>
     */
    #[Computed]
    public function articles(): Collection
    {
        return Article::query()
            ->with(['author', 'reviews' => fn ($query) => $query->select(['id', 'article_id', 'round', 'reviewer_id', 'decision'])])
            ->where('status', ArticleStatus::InReview)
            ->where('user_id', '!=', auth()->id())
            ->orderBy('submitted_at')
            ->get();
    }
}; ?>

<section class="w-full max-w-3xl">
    <x-page-header :title="__('Article review')" :subtitle="__('Mahasantri articles waiting for review. Any muhadhir can review: approve, or ask for changes with a note. Two approvals in a round publish the article.')" />

    <div class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
        @forelse ($this->articles as $article)
            @php($round = $article->reviews->where('round', $article->review_round))
            @php($done = $round->contains('reviewer_id', auth()->id()))
            <a wire:key="article-{{ $article->id }}" href="{{ route('articles.show', $article) }}" wire:navigate class="flex items-center gap-3 px-4 py-3 hover:bg-zinc-50 dark:hover:bg-zinc-800" data-test="review-{{ $article->id }}">
                <span class="min-w-0 flex-1">
                    <span class="block truncate font-medium">{{ $article->title }}</span>
                    <span class="block truncate text-xs text-zinc-500">
                        {{ $article->author->name }} · {{ __('sent :time', ['time' => $article->submitted_at?->diffForHumans()]) }}
                        · {{ __(':count of :needed approvals', ['count' => $round->where('decision', 'approved')->count(), 'needed' => ManageArticles::APPROVALS_NEEDED]) }}
                    </span>
                </span>
                @if ($done)
                    <flux:badge size="sm" color="green">{{ __('You reviewed') }}</flux:badge>
                @else
                    <flux:badge size="sm" color="blue">{{ __('To review') }}</flux:badge>
                @endif
            </a>
        @empty
            <flux:text class="p-4">{{ __('No articles wait for review.') }}</flux:text>
        @endforelse
    </div>
</section>
