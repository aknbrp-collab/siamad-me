<?php

use App\Actions\Articles\ManageArticles;
use App\Enums\ArticleStatus;
use App\Enums\Role;
use App\Models\Article;
use App\Models\ArticleReview;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Article')] class extends Component {
    public Article $article;

    public string $reviewNote = '';

    public function mount(Article $article): void
    {
        abort_unless(ManageArticles::canView(auth()->user(), $article), 403);
        $this->article = $article;
    }

    #[Computed]
    public function mine(): bool
    {
        return $this->article->user_id === auth()->id();
    }

    #[Computed]
    public function authorIsMuhadhir(): bool
    {
        return ManageArticles::isMuhadhir($this->article->author);
    }

    /**
     * Reviews, newest round first; shown to the author, muhadhir and staff.
     *
     * @return Collection<int, ArticleReview>
     */
    #[Computed]
    public function reviews(): Collection
    {
        return $this->article->reviews()->with('reviewer')->orderByDesc('round')->orderBy('id')->get();
    }

    #[Computed]
    public function canReview(): bool
    {
        return $this->article->status === ArticleStatus::InReview
            && ! $this->mine
            && ManageArticles::isMuhadhir(auth()->user())
            && ! $this->reviews->where('round', $this->article->review_round)->contains('reviewer_id', auth()->id());
    }

    #[Computed]
    public function canWithdraw(): bool
    {
        return $this->article->published_at !== null
            && $this->article->status !== ArticleStatus::Withdrawn
            && ($this->mine || auth()->user()->hasRole(Role::Admin, Role::Superadmin, Role::AcademicMonitor));
    }

    public function submit(ManageArticles $manage): void
    {
        $manage->submit($this->article, auth()->user());
        $this->refreshArticle(__('Sent for review. Two muhadhir approvals publish it.'));
    }

    public function publish(ManageArticles $manage): void
    {
        $manage->publish($this->article, auth()->user());
        $this->refreshArticle(__('Published. It is sent to the website editors.'));
    }

    public function review(bool $approve, ManageArticles $manage): void
    {
        $this->resetErrorBag();
        $manage->review($this->article, auth()->user(), $approve, $this->reviewNote);
        $this->reviewNote = '';
        $this->refreshArticle($approve ? __('Approved.') : __('Sent back to the author with your note.'));
    }

    public function withdraw(ManageArticles $manage): void
    {
        $manage->withdraw($this->article, auth()->user());
        $this->refreshArticle(__('Withdrawn.'));
    }

    public function discard(ManageArticles $manage): void
    {
        $manage->discard($this->article, auth()->user());
        Flux::toast(variant: 'success', text: __('Draft deleted.'));
        $this->redirectRoute('articles.mine', navigate: true);
    }

    private function refreshArticle(string $message): void
    {
        $this->article->refresh();
        unset($this->reviews, $this->canReview, $this->canWithdraw);
        Flux::toast(variant: 'success', text: $message);
    }
}; ?>

<section class="w-full max-w-3xl">
    <div class="mb-6 flex flex-wrap items-start gap-3">
        <div class="me-auto min-w-0">
            <flux:heading size="xl" level="1">{{ $article->title }}</flux:heading>
            <flux:text class="mt-1">
                {{ $article->author->teacher?->displayName() ?? $article->author->name }}
                @if ($article->published_at) · {{ $article->published_at->translatedFormat('j F Y') }} @endif
                @if ($article->code) · <span class="font-mono text-xs">{{ $article->code }}</span> @endif
            </flux:text>
        </div>
        <flux:badge :color="$article->status->color()">{{ $article->status->label() }}</flux:badge>
    </div>

    @error('article')
        <flux:callout variant="danger" icon="exclamation-triangle" class="mb-4" :text="$message" />
    @enderror

    @if ($this->mine || $this->canWithdraw)
        <div class="mb-6 flex flex-wrap gap-2">
            @if ($this->mine && $article->status !== ArticleStatus::InReview)
                <flux:button size="sm" icon="pencil-square" :href="route('articles.edit', $article)" wire:navigate>{{ __('Edit') }}</flux:button>
            @endif
            @if ($this->mine && ! $this->authorIsMuhadhir && in_array($article->status, [ArticleStatus::Draft, ArticleStatus::ChangesRequested], true))
                <flux:button size="sm" variant="primary" icon="paper-airplane" wire:click="submit" data-test="submit-article">{{ __('Send for review') }}</flux:button>
            @endif
            @if ($this->mine && $this->authorIsMuhadhir && $article->status !== ArticleStatus::Published && $article->status !== ArticleStatus::InReview)
                <flux:button size="sm" variant="primary" icon="globe-alt" wire:click="publish" data-test="publish-article">{{ $article->published_at ? __('Publish again') : __('Publish') }}</flux:button>
            @endif
            @if ($this->canWithdraw)
                <flux:button size="sm" variant="danger" icon="arrow-down-tray" wire:click="withdraw" wire:confirm="{{ __('Withdraw this article from the website?') }}" data-test="withdraw-article">{{ __('Withdraw') }}</flux:button>
            @endif
            @if ($this->mine && $article->status === ArticleStatus::Draft && ! $article->published_at && $article->review_round === 0)
                <flux:button size="sm" variant="ghost" icon="trash" wire:click="discard" wire:confirm="{{ __('Delete this draft?') }}">{{ __('Delete draft') }}</flux:button>
            @endif
        </div>
    @endif

    @if ($article->status === ArticleStatus::InReview)
        <flux:callout icon="clock" class="mb-6"
            :text="__('Waiting for review (round :round): :count of :needed approvals.', ['round' => $article->review_round, 'count' => $this->reviews->where('round', $article->review_round)->where('decision', 'approved')->count(), 'needed' => ManageArticles::APPROVALS_NEEDED])" />
    @endif

    @if ($article->summary)
        <p class="mb-4 text-base text-zinc-600 dark:text-zinc-300">{{ $article->summary }}</p>
    @endif
    <div class="article-body mb-6">{!! $article->bodyHtml() !!}</div>
    @if ($article->keywords)
        <div class="mb-8 flex flex-wrap gap-1">
            @foreach ($article->keywords as $keyword)
                <flux:badge size="sm">{{ $keyword }}</flux:badge>
            @endforeach
        </div>
    @endif

    @if ($this->canReview)
        <flux:card class="mb-8 space-y-3" data-test="review-form">
            <flux:heading>{{ __('Your review') }}</flux:heading>
            <flux:textarea wire:model="reviewNote" :label="__('Note for the author')" rows="3" :description:trailing="__('Required when you ask for changes.')" />
            <flux:error name="reviewNote" />
            <flux:error name="review" />
            <div class="flex flex-wrap gap-2">
                <flux:button variant="primary" icon="check" wire:click="review(true)" data-test="approve">{{ __('Approve') }}</flux:button>
                <flux:button icon="arrow-uturn-left" wire:click="review(false)" data-test="request-changes">{{ __('Ask for changes') }}</flux:button>
            </div>
        </flux:card>
    @endif

    @if (! $this->authorIsMuhadhir && $this->reviews->isNotEmpty() && ($this->mine || ManageArticles::isMuhadhir(auth()->user()) || auth()->user()->hasRole(Role::Admin, Role::Superadmin, Role::AcademicMonitor)))
        <flux:heading class="mb-2">{{ __('Reviews') }}</flux:heading>
        <div class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
            @foreach ($this->reviews as $review)
                <div wire:key="review-{{ $review->id }}" class="px-4 py-3 text-sm">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-medium">{{ $review->reviewer->name }}</span>
                        <flux:badge size="sm" :color="$review->approved() ? 'green' : 'amber'">{{ $review->approved() ? __('Approved') : __('Changes asked') }}</flux:badge>
                        <span class="text-xs text-zinc-500">{{ __('Round :round', ['round' => $review->round]) }} · {{ $review->created_at->translatedFormat('j M Y H:i') }}</span>
                    </div>
                    @if ($review->note)
                        <div class="mt-1 whitespace-pre-line text-zinc-600 dark:text-zinc-300">{{ $review->note }}</div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</section>
