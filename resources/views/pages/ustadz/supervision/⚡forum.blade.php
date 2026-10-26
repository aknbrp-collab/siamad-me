<?php

use App\Models\Teacher;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Group forum')] class extends Component {
    public Teacher $supervisor;

    public function mount(): void
    {
        $this->supervisor = auth()->user()->teacher ?? abort(403);
    }
}; ?>

<section class="w-full max-w-4xl">
    <x-page-header :title="__('Group forum')" :subtitle="__('Discussion with the mahasantri you supervise as pembimbing akademik.')">
        <x-slot:actions>
            <flux:button size="sm" variant="ghost" icon="arrow-left" :href="route('ustadz.supervision')" wire:navigate>{{ __('My supervisees') }}</flux:button>
        </x-slot:actions>
    </x-page-header>

    <livewire:pages::shared.forum :supervisor="$supervisor" />
</section>
