<?php

use App\Actions\Articles\ManageArticles;
use App\Models\Article;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('My articles')] class extends Component {
    public function mount(): void
    {
        abort_unless(ManageArticles::canWrite(auth()->user()), 403);
    }

    /**
     * @return Collection<int, Article>
     */
    #[Computed]
    public function articles(): Collection
    {
        return Article::query()->where('user_id', auth()->id())->latest('updated_at')->get();
    }

    #[Computed]
    public function muhadhir(): bool
    {
        return ManageArticles::isMuhadhir(auth()->user());
    }
}; ?>

<section class="w-full max-w-3xl">
    <x-page-header :title="__('My articles')" :subtitle="$this->muhadhir
        ? __('Simple articles for the public website. Yours are published directly, then checked by the website editors.')
        : __('Simple articles for the public website. Send yours for review: it is published after two muhadhir approve it, then checked by the website editors.')">
        <x-slot:actions>
            @if ($this->muhadhir)
                <flux:button icon="document-magnifying-glass" :href="route('articles.review')" wire:navigate>{{ __('Review queue') }}</flux:button>
            @endif
            <flux:button variant="primary" icon="plus" :href="route('articles.create')" wire:navigate data-test="new-article">{{ __('Write an article') }}</flux:button>
        </x-slot:actions>
    </x-page-header>

    <div class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
        @forelse ($this->articles as $article)
            <a wire:key="article-{{ $article->id }}" href="{{ route('articles.show', $article) }}" wire:navigate class="flex items-center gap-3 px-4 py-3 hover:bg-zinc-50 dark:hover:bg-zinc-800">
                <flux:icon.document-text class="size-5 shrink-0 text-zinc-400" />
                <span class="min-w-0 flex-1">
                    <span class="block truncate font-medium">{{ $article->title }}</span>
                    <span class="block truncate text-xs text-zinc-500">
                        {{ __('Changed :time', ['time' => $article->updated_at->diffForHumans()]) }}
                        @if ($article->published_at && $article->status !== \App\Enums\ArticleStatus::Published && $article->status !== \App\Enums\ArticleStatus::Withdrawn)
                            · {{ __('the published version stays online') }}
                        @endif
                    </span>
                </span>
                <flux:badge size="sm" :color="$article->status->color()">{{ $article->status->label() }}</flux:badge>
            </a>
        @empty
            <flux:text class="p-4">{{ __('No articles yet.') }}</flux:text>
        @endforelse
    </div>
</section>
