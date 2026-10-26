<?php

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Notifications\DatabaseNotification;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Notifications')] class extends Component {
    use WithPagination;

    /**
     * @return LengthAwarePaginator<int, DatabaseNotification>
     */
    #[Computed]
    public function notifications(): LengthAwarePaginator
    {
        return auth()->user()->notifications()->latest()->paginate(25);
    }

    public function markAllRead(): void
    {
        auth()->user()->unreadNotifications()->update(['read_at' => now()]);
        unset($this->notifications);
    }
}; ?>

<section class="w-full max-w-3xl">
    <x-page-header :title="__('Notifications')" :subtitle="__('New forum messages, new work, deadlines, bills and transfers.')">
        <x-slot:actions>
            @if (auth()->user()->unreadNotifications()->exists())
                <flux:button size="sm" icon="check" wire:click="markAllRead">{{ __('Mark all as read') }}</flux:button>
            @endif
        </x-slot:actions>
    </x-page-header>

    <div class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
        @forelse ($this->notifications as $notification)
            <a wire:key="notification-{{ $notification->id }}" href="{{ route('notifications.open', $notification->id) }}"
                @class([
                    'flex items-start gap-3 px-4 py-3 transition hover:bg-zinc-50 dark:hover:bg-zinc-800',
                    'bg-blue-50/60 dark:bg-blue-950/30' => $notification->read_at === null,
                ])>
                <flux:icon :icon="$notification->data['icon'] ?? 'bell'" class="mt-0.5 size-5 shrink-0 text-zinc-500" />
                <span class="min-w-0 flex-1">
                    <span @class(['block text-sm', 'font-semibold' => $notification->read_at === null, 'font-medium' => $notification->read_at !== null])>{{ $notification->data['title'] ?? '' }}</span>
                    <span class="block text-sm text-zinc-600 dark:text-zinc-400">{{ $notification->data['body'] ?? '' }}</span>
                    <span class="block text-xs text-zinc-500">{{ $notification->created_at->diffForHumans() }}</span>
                </span>
                @if ($notification->read_at === null)
                    <span class="mt-1.5 size-2 shrink-0 rounded-full bg-blue-500" aria-label="{{ __('Unread') }}"></span>
                @endif
            </a>
        @empty
            <flux:text class="p-4 text-sm">{{ __('No notifications yet.') }}</flux:text>
        @endforelse
    </div>

    <div class="mt-4">{{ $this->notifications->links() }}</div>
</section>
