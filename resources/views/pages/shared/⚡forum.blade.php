<?php

use App\Actions\Lms\ManageForum;
use App\Models\CourseOffering;
use App\Models\ForumPost;
use App\Models\ForumThread;
use App\Models\Teacher;
use App\Support\Forum\ForumContext;
use App\Support\Forum\OfferingForum;
use App\Support\Forum\SupervisionForum;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Forum discussion, embedded in the class pages (pass an offering) and the
 * pembimbing akademik pages (pass the supervising muhadhir).
 */
new class extends Component {
    /** offering | supervision */
    #[Locked]
    public string $scope = 'offering';

    #[Locked]
    public int $scopeId;

    #[Url(as: 'thread')]
    public ?int $threadId = null;

    public string $newTitle = '';
    public string $newBody = '';
    public string $reply = '';

    public function mount(?CourseOffering $offering = null, ?Teacher $supervisor = null): void
    {
        [$this->scope, $this->scopeId] = match (true) {
            $offering !== null => ['offering', $offering->id],
            $supervisor !== null => ['supervision', $supervisor->id],
            default => abort(404),
        };

        abort_unless($this->context->participates(auth()->user()), 403);
    }

    #[Computed]
    public function context(): ForumContext
    {
        return $this->scope === 'offering'
            ? new OfferingForum(CourseOffering::query()->with('semester')->findOrFail($this->scopeId))
            : new SupervisionForum(Teacher::query()->findOrFail($this->scopeId));
    }

    #[Computed]
    public function isTeacher(): bool
    {
        return $this->context->moderates(auth()->user());
    }

    /**
     * @return Collection<int, ForumThread>
     */
    #[Computed]
    public function threads(): Collection
    {
        return $this->context->threads()
            ->with('user')
            ->withCount('posts')
            ->orderByDesc('is_pinned')
            ->orderByDesc('last_posted_at')
            ->get();
    }

    #[Computed]
    public function thread(): ?ForumThread
    {
        return $this->threadId === null
            ? null
            : $this->context->threads()->with(['user', 'posts.user'])->find($this->threadId);
    }

    public function open(int $id): void
    {
        $this->threadId = $id;
        $this->reply = '';
        $this->resetErrorBag();
    }

    public function back(): void
    {
        $this->threadId = null;
    }

    public function startThread(ManageForum $forum): void
    {
        $this->resetErrorBag();

        $thread = $forum->startThread($this->context, auth()->user(), $this->newTitle, $this->newBody);

        $this->reset('newTitle', 'newBody');
        Flux::modal('new-thread')->close();
        $this->open($thread->id);
    }

    public function postReply(ManageForum $forum): void
    {
        $this->resetErrorBag();

        $forum->reply($this->context->threads()->findOrFail($this->threadId), auth()->user(), $this->reply);

        $this->reply = '';
        unset($this->thread);
    }

    public function togglePin(ManageForum $forum): void
    {
        $forum->togglePin($this->context->threads()->findOrFail($this->threadId), auth()->user());
        unset($this->thread, $this->threads);
    }

    public function toggleLock(ManageForum $forum): void
    {
        $forum->toggleLock($this->context->threads()->findOrFail($this->threadId), auth()->user());
        unset($this->thread);
    }

    public function deletePost(int $postId, ManageForum $forum): void
    {
        $this->resetErrorBag();

        $post = ForumPost::query()->whereIn('forum_thread_id', $this->context->threads()->select('id'))->findOrFail($postId);
        $forum->deletePost($post, auth()->user());

        unset($this->thread);
    }

    public function deleteThread(ManageForum $forum): void
    {
        $this->resetErrorBag();

        $forum->deleteThread($this->context->threads()->findOrFail($this->threadId), auth()->user());

        $this->threadId = null;
        unset($this->threads);
    }
}; ?>

<div wire:poll.visible.15s>
    @php($readOnly = $this->context->isReadOnly())

    @foreach (['reply', 'title', 'body', 'semester'] as $key)
        @error($key)
            <flux:callout variant="danger" icon="exclamation-triangle" class="mb-4" :text="$message" />
        @enderror
    @endforeach

    @if ($this->thread)
        @php($thread = $this->thread)
        <div class="mb-4 flex flex-wrap items-center gap-2">
            <flux:button size="sm" variant="ghost" icon="arrow-left" wire:click="back">{{ __('All topics') }}</flux:button>
            <span class="me-auto"></span>
            @if ($this->isTeacher && ! $readOnly)
                <flux:button size="sm" icon="bookmark" wire:click="togglePin">{{ $thread->is_pinned ? __('Unpin') : __('Pin') }}</flux:button>
                <flux:button size="sm" icon="lock-closed" wire:click="toggleLock">{{ $thread->is_locked ? __('Unlock') : __('Lock') }}</flux:button>
            @endif
            @if (! $readOnly && ($this->isTeacher || $thread->user_id === auth()->id()))
                <flux:button size="sm" variant="danger" icon="trash" wire:click="deleteThread" wire:confirm="{{ __('Remove this topic?') }}">{{ __('Remove') }}</flux:button>
            @endif
        </div>

        <flux:card class="mb-4 space-y-2">
            <flux:heading size="lg">{{ $thread->title }}</flux:heading>
            <flux:text class="text-xs">{{ $thread->user->name }} · {{ $thread->created_at->diffForHumans() }}</flux:text>
            <div class="whitespace-pre-line text-sm">{{ $thread->body }}</div>
        </flux:card>

        <div class="mb-4 flex flex-col gap-2">
            @foreach ($thread->posts as $post)
                <div wire:key="post-{{ $post->id }}" class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                    <div class="mb-1 flex items-center justify-between gap-2">
                        <flux:text class="text-xs"><span class="font-medium text-zinc-800 dark:text-zinc-200">{{ $post->user->name }}</span> · {{ $post->created_at->diffForHumans() }}</flux:text>
                        @if (! $readOnly && ($this->isTeacher || $post->user_id === auth()->id()))
                            <flux:button size="xs" variant="ghost" icon="trash" wire:click="deletePost({{ $post->id }})" wire:confirm="{{ __('Remove this message?') }}" :aria-label="__('Remove')" />
                        @endif
                    </div>
                    <div class="whitespace-pre-line text-sm">{{ $post->body }}</div>
                </div>
            @endforeach
        </div>

        @if ($readOnly)
            <flux:text class="text-sm">{{ __('This semester is closed; the forum is read-only.') }}</flux:text>
        @elseif ($thread->is_locked && ! $this->isTeacher)
            <flux:callout icon="lock-closed" :text="__('This topic is locked.')" />
        @else
            <form wire:submit="postReply" class="space-y-2">
                <flux:textarea wire:model="reply" :placeholder="__('Write a reply…')" rows="3" />
                <flux:button type="submit" variant="primary" size="sm" data-test="post-reply">{{ __('Reply') }}</flux:button>
            </form>
        @endif
    @else
        @unless ($readOnly)
            <flux:modal.trigger name="new-thread">
                <flux:button variant="primary" icon="plus" class="mb-4">{{ __('New topic') }}</flux:button>
            </flux:modal.trigger>
        @endunless

        <div class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
            @forelse ($this->threads as $item)
                <button type="button" wire:key="thread-{{ $item->id }}" wire:click="open({{ $item->id }})"
                    class="flex w-full items-center gap-3 px-3 py-2 text-start hover:bg-zinc-50 dark:hover:bg-zinc-800">
                    <span class="flex-1">
                        <span class="flex items-center gap-1 font-medium">
                            @if ($item->is_pinned)<flux:icon.bookmark variant="micro" class="text-amber-500" />@endif
                            @if ($item->is_locked)<flux:icon.lock-closed variant="micro" class="text-zinc-400" />@endif
                            {{ $item->title }}
                        </span>
                        <span class="block text-xs text-zinc-500">{{ $item->user->name }} · {{ $item->last_posted_at->diffForHumans() }}</span>
                    </span>
                    <flux:badge size="sm">{{ $item->posts_count }}</flux:badge>
                </button>
            @empty
                <flux:text class="p-3 text-sm">{{ __('No topics yet. Start the discussion.') }}</flux:text>
            @endforelse
        </div>
    @endif

    <flux:modal name="new-thread" class="w-full max-w-lg">
        <form wire:submit="startThread" class="space-y-6">
            <flux:heading size="lg">{{ __('New topic') }}</flux:heading>
            <flux:input wire:model="newTitle" :label="__('Title')" required />
            <flux:textarea wire:model="newBody" :label="__('Message')" rows="5" required />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Post') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
