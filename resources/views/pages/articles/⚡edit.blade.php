<?php

use App\Actions\Articles\ManageArticles;
use App\Models\Article;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Write an article')] class extends Component {
    public ?Article $article = null;

    public string $title = '';
    public string $summary = '';
    public string $keywords = '';
    public string $body = '';

    /** write | preview */
    public string $mode = 'write';

    public function mount(?Article $article = null): void
    {
        abort_unless(ManageArticles::canWrite(auth()->user()), 403);

        if ($article?->exists) {
            abort_unless($article->user_id === auth()->id(), 403);
            $this->article = $article;
            $this->title = $article->title;
            $this->summary = (string) $article->summary;
            $this->keywords = implode(', ', $article->keywords ?? []);
            $this->body = $article->body_md;
        }
    }

    #[Computed]
    public function html(): string
    {
        return Article::render($this->body);
    }

    public function save(ManageArticles $manage): void
    {
        $this->resetErrorBag();
        $article = $manage->save($this->article, auth()->user(), [
            'title' => $this->title, 'summary' => $this->summary, 'body' => $this->body, 'keywords' => $this->keywords,
        ]);

        Flux::toast(variant: 'success', text: __('Saved.'));
        $this->redirectRoute('articles.show', $article, navigate: true);
    }
}; ?>

<section class="w-full max-w-3xl">
    <x-page-header :title="$article ? __('Edit article') : __('Write an article')"
        :subtitle="__('Write in Markdown: **bold**, *italic*, # heading, - list, > quote, [link](https://…). A simple article for the public website, not a journal paper.')" />

    @if ($article?->published_at && $article->status->value === 'published')
        <flux:callout icon="information-circle" class="mb-4" :text="__('This article is published. Your changes become a new version; the published one stays online until the new one is published.')" />
    @endif
    @error('title')
        @if (str_contains($message, 'review'))
            <flux:callout variant="danger" icon="exclamation-triangle" class="mb-4" :text="$message" />
        @endif
    @enderror

    <form wire:submit="save" class="space-y-5">
        <flux:input wire:model="title" :label="__('Title')" required />
        <flux:textarea wire:model="summary" :label="__('Summary (optional)')" rows="2" :description:trailing="__('A few sentences shown under the title on the website.')" />
        <flux:input wire:model="keywords" :label="__('Keywords (optional)')" :placeholder="__('e.g. zakat, muamalah')" :description:trailing="__('Separated by commas, up to 10.')" />

        <div>
            <div class="mb-2 flex items-center justify-between">
                <flux:label>{{ __('Text') }}</flux:label>
                <flux:radio.group wire:model.live="mode" variant="segmented" size="sm">
                    <flux:radio value="write" :label="__('Write')" />
                    <flux:radio value="preview" :label="__('Preview')" />
                </flux:radio.group>
            </div>
            @if ($mode === 'preview')
                <div class="article-body rounded-lg border border-zinc-200 p-4 dark:border-zinc-700" data-test="article-preview">{!! $this->html !!}</div>
            @else
                <flux:textarea wire:model="body" rows="18" class="font-mono text-sm" />
            @endif
            <flux:error name="body" />
        </div>

        <div class="flex justify-end gap-2">
            <flux:button variant="ghost" :href="$article ? route('articles.show', $article) : route('articles.mine')" wire:navigate>{{ __('Cancel') }}</flux:button>
            <flux:button type="submit" variant="primary" data-test="save-article">{{ __('Save') }}</flux:button>
        </div>
    </form>
</section>
