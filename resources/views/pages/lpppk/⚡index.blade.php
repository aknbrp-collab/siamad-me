<?php

use App\Actions\Discussions\ManageDiscussions;
use App\Enums\DiscussionKind;
use App\Models\Discussion;
use App\Models\DiscussionMessage;
use App\Support\DiscussionAccess;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('LPPPK')] class extends Component {
    public ?int $editingId = null;
    public string $name = '';
    public string $description = '';

    public function mount(): void
    {
        abort_unless(DiscussionAccess::canUseLpppk(auth()->user()), 403);
    }

    #[Computed]
    public function moderator(): bool
    {
        return DiscussionAccess::oversees(auth()->user());
    }

    /**
     * Open channels first.
     *
     * @return Collection<int, Discussion>
     */
    #[Computed]
    public function channels(): Collection
    {
        return Discussion::query()
            ->where('kind', DiscussionKind::Lpppk)
            ->orderByRaw('case when closed_at is null then 0 else 1 end')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return array<int, int>
     */
    #[Computed]
    public function unread(): array
    {
        return app(ManageDiscussions::class)->unreadLpppk(auth()->user());
    }

    /**
     * @return \Illuminate\Support\Collection<int, DiscussionMessage>
     */
    #[Computed]
    public function latest(): \Illuminate\Support\Collection
    {
        $ids = DiscussionMessage::query()
            ->whereIn('discussion_id', $this->channels->modelKeys())
            ->whereNull('hidden_at')
            ->groupBy('discussion_id')
            ->selectRaw('max(id) as id')
            ->pluck('id');

        return DiscussionMessage::query()->with('user')->whereKey($ids)->get()->keyBy('discussion_id');
    }

    #[Computed]
    public function openQuestions(): int
    {
        return \App\Models\LegalQuestion::query()->whereDoesntHave('answers', fn ($query) => $query->where('status', 'sent'))->count();
    }

    public function create(): void
    {
        abort_unless($this->moderator, 403);
        $this->reset('editingId', 'name', 'description');
        $this->resetErrorBag();
        Flux::modal('channel-form')->show();
    }

    public function edit(int $id): void
    {
        abort_unless($this->moderator, 403);
        $channel = Discussion::query()->where('kind', DiscussionKind::Lpppk)->findOrFail($id);
        $this->editingId = $channel->id;
        $this->name = $channel->name;
        $this->description = (string) $channel->description;
        $this->resetErrorBag();
        Flux::modal('channel-form')->show();
    }

    public function save(ManageDiscussions $manage): void
    {
        abort_unless($this->moderator, 403);
        $this->resetErrorBag();

        if ($this->editingId) {
            $manage->rename(Discussion::query()->where('kind', DiscussionKind::Lpppk)->findOrFail($this->editingId), $this->name, $this->description);
        } else {
            $manage->create(DiscussionKind::Lpppk, $this->name, $this->description, null, auth()->user());
        }

        Flux::modal('channel-form')->close();
        unset($this->channels);
        Flux::toast(variant: 'success', text: __('Channel saved.'));
    }

    public function toggleClosed(int $id, ManageDiscussions $manage): void
    {
        $channel = Discussion::query()->where('kind', DiscussionKind::Lpppk)->findOrFail($id);
        $manage->setClosed($channel, $channel->closed_at === null, auth()->user());
        unset($this->channels);
    }
}; ?>

<section class="w-full max-w-3xl">
    <x-page-header :title="__('LPPPK')" :subtitle="__('Lembaga Penelitian, Pengembangan, dan Pengabdian Keilmuan: discussion channels for every mahasantri and muhadhir. Chat, share files and keep notes together.')">
        @if ($this->moderator)
            <x-slot:actions>
                <flux:button icon="shield-check" :href="route('lpppk.moderation')" wire:navigate>{{ __('Moderation') }}</flux:button>
                <flux:button variant="primary" icon="plus" wire:click="create" data-test="add-channel">{{ __('Add channel') }}</flux:button>
            </x-slot:actions>
        @endif
    </x-page-header>

    @if ($sanction = \App\Support\DiscussionAccess::sanction(auth()->user()))
        @if ($sanction->isMuted())
            <flux:callout icon="speaker-x-mark" color="amber" class="mb-4"
                :text="($sanction->muted_until ? __('You are muted in LPPPK until :date.', ['date' => $sanction->muted_until->translatedFormat('j M Y H:i')]) : __('You are muted in LPPPK.')).($sanction->reason ? ' '.$sanction->reason : '')" />
        @endif
    @endif

    <a href="{{ route('tanya-hukum') }}" wire:navigate class="mb-4 flex items-center gap-3 rounded-lg border border-zinc-200 px-4 py-3 hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800" data-test="tanya-hukum-link">
        <flux:icon.scale class="size-5 shrink-0 text-zinc-400" />
        <span class="min-w-0 flex-1">
            <span class="block font-medium">{{ __('Tanya Hukum') }}</span>
            <span class="block text-xs text-zinc-500">{{ __('Questions from the website: discuss them and answer.') }}</span>
        </span>
        @if ($this->openQuestions > 0)
            <flux:badge size="sm" color="amber">{{ trans_choice(':count not answered|:count not answered', $this->openQuestions) }}</flux:badge>
        @endif
    </a>

    <div class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
        @forelse ($this->channels as $channel)
            @php($last = $this->latest->get($channel->id))
            <div wire:key="channel-{{ $channel->id }}" class="flex items-center gap-3 px-4 py-3 hover:bg-zinc-50 dark:hover:bg-zinc-800">
                <a href="{{ route('discussions.show', $channel) }}" wire:navigate class="flex min-w-0 flex-1 items-center gap-3" data-test="channel-{{ $channel->id }}">
                    <flux:icon.hashtag class="size-5 shrink-0 text-zinc-400" />
                    <span class="min-w-0 flex-1">
                        <span class="flex items-center gap-2">
                            <span class="truncate font-medium">{{ $channel->name }}</span>
                            @if ($channel->closed_at)
                                <flux:badge size="sm" color="zinc">{{ __('Closed') }}</flux:badge>
                            @elseif ($channel->locked_at)
                                <flux:badge size="sm" color="amber">{{ __('Locked') }}</flux:badge>
                            @endif
                        </span>
                        <span class="block truncate text-xs text-zinc-500">
                            @if ($last)
                                {{ $last->user->name }}: {{ Str::limit((string) ($last->body ?? __('shared a file')), 70) }}
                            @else
                                {{ $channel->description ?? __('No messages yet.') }}
                            @endif
                        </span>
                    </span>
                    @if ($count = $this->unread[$channel->id] ?? 0)
                        <flux:badge size="sm" color="green">{{ $count }}</flux:badge>
                    @endif
                </a>
                @if ($this->moderator)
                    <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $channel->id }})" :aria-label="__('Edit')" />
                    <flux:button size="sm" variant="ghost" :icon="$channel->closed_at ? 'arrow-uturn-left' : 'archive-box'" wire:click="toggleClosed({{ $channel->id }})"
                        wire:confirm="{{ $channel->closed_at ? __('Reopen this channel?') : __('Close this channel? It stays readable.') }}" :aria-label="$channel->closed_at ? __('Reopen') : __('Close')" />
                @endif
            </div>
        @empty
            <flux:text class="p-4">{{ __('No channels yet.') }}</flux:text>
        @endforelse
    </div>

    <flux:modal name="channel-form" class="w-full max-w-md">
        <form wire:submit="save" class="space-y-5">
            <flux:heading size="lg">{{ $editingId ? __('Edit channel') : __('Add channel') }}</flux:heading>
            <flux:input wire:model="name" :label="__('Name')" :placeholder="__('e.g. Penelitian')" required />
            <flux:textarea wire:model="description" :label="__('Description (optional)')" rows="2" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
