<?php

use App\Actions\Discussions\ManageDiscussions;
use App\Enums\DiscussionKind;
use App\Models\Discussion;
use App\Models\DiscussionMessage;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Bimbingan')] class extends Component {
    /**
     * My bimbingan groups (led or joined), active ones first.
     *
     * @return Collection<int, Discussion>
     */
    #[Computed]
    public function groups(): Collection
    {
        return Discussion::query()
            ->with('leadTeacher.user')
            ->withCount(['members as students_count' => fn ($query) => $query->where('role', 'member')])
            ->where('kind', DiscussionKind::Bimbingan)
            ->whereHas('members', fn ($query) => $query->where('user_id', auth()->id()))
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
        return app(ManageDiscussions::class)->unread(auth()->user(), DiscussionKind::Bimbingan);
    }

    /**
     * The latest message of each group, by group id.
     *
     * @return \Illuminate\Support\Collection<int, DiscussionMessage>
     */
    #[Computed]
    public function latest(): \Illuminate\Support\Collection
    {
        $ids = DiscussionMessage::query()
            ->whereIn('discussion_id', $this->groups->modelKeys())
            ->whereNull('hidden_at')
            ->groupBy('discussion_id')
            ->selectRaw('max(id) as id')
            ->pluck('id');

        return DiscussionMessage::query()->with('user')->whereKey($ids)->get()->keyBy('discussion_id');
    }
}; ?>

<section class="w-full max-w-3xl">
    <x-page-header :title="__('Bimbingan')" :subtitle="__('Your bimbingan penelitian dan penulisan groups. Each has a discussion room with chat, shared files and notes.')" />

    <div class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
        @forelse ($this->groups as $group)
            @php($last = $this->latest->get($group->id))
            <a wire:key="group-{{ $group->id }}" href="{{ route('discussions.show', $group) }}" wire:navigate
                class="flex items-center gap-3 px-4 py-3 hover:bg-zinc-50 dark:hover:bg-zinc-800" data-test="group-{{ $group->id }}">
                <flux:icon.user-group class="size-6 shrink-0 text-zinc-400" />
                <span class="min-w-0 flex-1">
                    <span class="flex items-center gap-2">
                        <span class="truncate font-medium">{{ $group->name }}</span>
                        @if ($group->closed_at)
                            <flux:badge size="sm" color="zinc">{{ __('Closed') }}</flux:badge>
                        @endif
                    </span>
                    <span class="block truncate text-xs text-zinc-500">
                        {{ $group->leadTeacher?->displayName() }} · {{ trans_choice(':count mahasantri|:count mahasantri', $group->students_count) }}
                        @if ($last)
                            · {{ $last->user->name }}: {{ Str::limit((string) ($last->body ?? __('shared a file')), 60) }}
                        @endif
                    </span>
                </span>
                @if ($count = $this->unread[$group->id] ?? 0)
                    <flux:badge size="sm" color="green">{{ $count }}</flux:badge>
                @endif
            </a>
        @empty
            <flux:text class="p-4">{{ __('You are not in a bimbingan group yet. The academic office adds you.') }}</flux:text>
        @endforelse
    </div>
</section>
