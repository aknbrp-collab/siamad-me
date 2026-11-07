<?php

use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('App devices')] class extends Component {
    /**
     * Phones and tablets signed in to the SIAKAD app (one API token each, v6 D37).
     *
     * @return Collection<int, PersonalAccessToken>
     */
    #[Computed]
    public function devices(): Collection
    {
        return auth()->user()->tokens()->latest('last_used_at')->latest('id')->get();
    }

    public function signOut(int $id): void
    {
        auth()->user()->tokens()->whereKey($id)->delete();
        unset($this->devices);
        Flux::toast(variant: 'success', text: __('The device was signed out.'));
    }

    public function signOutAll(): void
    {
        auth()->user()->tokens()->delete();
        unset($this->devices);
        Flux::toast(variant: 'success', text: __('All devices were signed out.'));
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('App devices') }}</flux:heading>

    <x-pages::settings.layout :heading="__('App devices')" :subheading="__('Phones and tablets signed in to the SIAKAD app. Sign out one you no longer use or have lost.')">
        <div class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700" data-test="devices">
            @forelse ($this->devices as $device)
                <div wire:key="device-{{ $device->id }}" class="flex items-center gap-3 px-4 py-3">
                    <flux:icon :icon="in_array($device->getAttribute('platform'), ['android', 'ios'], true) ? 'device-phone-mobile' : 'computer-desktop'" class="size-5 shrink-0 text-zinc-400" />
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm font-medium">{{ $device->name }}</span>
                        <span class="block text-xs text-zinc-500">
                            {{ __('Signed in :date', ['date' => $device->created_at?->translatedFormat('j M Y')]) }}
                            · {{ $device->last_used_at ? __('last used :when', ['when' => $device->last_used_at->diffForHumans()]) : __('not used yet') }}
                        </span>
                    </span>
                    <flux:button size="sm" variant="ghost" wire:click="signOut({{ $device->id }})" wire:confirm="{{ __('Sign out this device?') }}">{{ __('Sign out') }}</flux:button>
                </div>
            @empty
                <flux:text class="p-4">{{ __('No device is signed in to the app.') }}</flux:text>
            @endforelse
        </div>

        @if ($this->devices->count() > 1)
            <flux:button class="mt-4" variant="danger" size="sm" wire:click="signOutAll" wire:confirm="{{ __('Sign out all devices?') }}">{{ __('Sign out all devices') }}</flux:button>
        @endif
    </x-pages::settings.layout>
</section>
