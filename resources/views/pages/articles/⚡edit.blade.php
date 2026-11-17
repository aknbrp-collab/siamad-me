<?php

use App\Actions\Articles\ManageArticles;
use App\Models\Article;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Write an article')] class extends Component {
    use WithFileUploads;

    public ?Article $article = null;

    public string $title = '';
    public string $summary = '';
    public string $keywords = '';
    public string $body = '';

    /** The optional picture (v7 D48): a new upload, its caption, or removing the current one. */
    public mixed $image = null;
    public string $imageCaption = '';
    public bool $removeImage = false;

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
            $this->imageCaption = (string) $article->image_caption;
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
            'image' => $this->image, 'image_caption' => $this->imageCaption, 'remove_image' => $this->removeImage,
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

        <flux:fieldset>
            <flux:legend>{{ __('Picture (optional)') }}</flux:legend>
            <flux:description>{{ __('One picture shown above the text, here and on the website. JPG, PNG or WebP; large pictures are made 1600 px wide.') }}</flux:description>
            <div class="mt-3 flex flex-col gap-3 sm:flex-row sm:items-start">
                @if ($image && method_exists($image, 'isPreviewable') && $image->isPreviewable())
                    <img src="{{ $image->temporaryUrl() }}" alt="" class="h-28 w-44 rounded-lg object-cover" data-test="image-preview">
                @elseif ($article?->image_path && ! $removeImage)
                    <img src="{{ route('articles.image', $article) }}?v={{ substr(md5($article->image_path), 0, 8) }}" alt="" class="h-28 w-44 rounded-lg object-cover" data-test="image-current">
                @endif
                <div class="min-w-0 flex-1 space-y-2">
                    <input type="file" wire:model="image" accept=".jpg,.jpeg,.png,.webp" class="block w-full text-sm file:me-3 file:rounded-md file:border-0 file:bg-zinc-100 file:px-3 file:py-1.5 dark:file:bg-zinc-700" data-test="article-image">
                    <span wire:loading wire:target="image" class="text-xs text-zinc-500">{{ __('Uploading…') }}</span>
                    <flux:error name="image" />
                    @if ($image || ($article?->image_path && ! $removeImage))
                        <flux:input wire:model="imageCaption" size="sm" :placeholder="__('Caption (optional)')" />
                        <flux:error name="image_caption" />
                    @endif
                    @if ($article?->image_path && ! $image)
                        <flux:checkbox wire:model.live="removeImage" :label="__('Remove the picture')" />
                    @endif
                </div>
            </div>
        </flux:fieldset>

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
