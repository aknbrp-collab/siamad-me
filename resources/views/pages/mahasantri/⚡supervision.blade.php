<?php

use App\Models\Supervision;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Academic supervisor')] class extends Component {
    #[Computed]
    public function supervision(): ?Supervision
    {
        return auth()->user()->student?->currentSupervision()->with('teacher.user')->first();
    }
}; ?>

<section class="w-full max-w-4xl">
    <x-page-header :title="__('Academic supervisor')" :subtitle="__('Your pembimbing akademik guides your studies. Ask questions in the group forum.')" />

    @if ($this->supervision)
        @php($teacher = $this->supervision->teacher)
        <flux:card class="mb-8">
            <dl class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-[auto_1fr]" data-test="supervisor">
                <dt class="text-zinc-500">{{ __('Pembimbing akademik') }}</dt>
                <dd class="font-medium">{{ $teacher->displayName() }}</dd>
                @if ($teacher->phone)
                    <dt class="text-zinc-500">{{ __('Phone') }}</dt><dd>{{ $teacher->phone }}</dd>
                @endif
                @if ($teacher->user->email)
                    <dt class="text-zinc-500">{{ __('Email') }}</dt><dd>{{ $teacher->user->email }}</dd>
                @endif
                <dt class="text-zinc-500">{{ __('Since') }}</dt><dd>{{ $this->supervision->started_on->translatedFormat('j F Y') }}</dd>
            </dl>
        </flux:card>

        <flux:heading size="lg" class="mb-3">{{ __('Group forum') }}</flux:heading>
        <livewire:pages::shared.forum :supervisor="$teacher" />
    @else
        <flux:text>{{ __('No pembimbing akademik has been assigned to you yet. Please contact the academic office.') }}</flux:text>
    @endif
</section>
