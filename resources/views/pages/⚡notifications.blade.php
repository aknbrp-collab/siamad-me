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

    <div wire:ignore x-data="pushDevice(@js(auth()->user()->pushSubscriptions()->pluck('endpoint')))" x-cloak x-show="state !== 'unconfigured'"
        class="mb-6 flex flex-wrap items-center gap-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700" data-test="push-device">
        <flux:icon.device-phone-mobile class="size-6 shrink-0 text-zinc-500" />
        <div class="min-w-0 flex-1 text-sm">
            <div class="font-medium">{{ __('Pop-ups on this device') }}</div>
            <div class="text-zinc-600 dark:text-zinc-400">
                <span x-show="state === 'on'">{{ __('On: new notifications pop up on this device even when SIAKAD is closed.') }}</span>
                <span x-show="state === 'off'">{{ __('Off: notifications appear only here, in the bell.') }}</span>
                <span x-show="state === 'denied'">{{ __('Blocked in this browser. Allow notifications for this site in the browser settings, then reload.') }}</span>
                <span x-show="state === 'install'">{{ __('On iPhone or iPad, first add SIAKAD to the Home Screen (Share → Add to Home Screen) and open it from there.') }}</span>
                <span x-show="state === 'unsupported'">{{ __('This browser does not support pop-up notifications.') }}</span>
                <span x-show="failed" class="block text-red-600 dark:text-red-400">{{ __('That did not work. Check the connection and try again.') }}</span>
            </div>
        </div>
        <flux:button x-show="state === 'off'" size="sm" variant="primary" icon="bell-alert" x-on:click="enable" x-bind:disabled="busy">{{ __('Turn on for this device') }}</flux:button>
        <flux:button x-show="state === 'on'" size="sm" icon="bell-slash" x-on:click="disable" x-bind:disabled="busy">{{ __('Turn off') }}</flux:button>
    </div>

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
