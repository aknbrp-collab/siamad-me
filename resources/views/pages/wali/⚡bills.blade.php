<?php

use App\Models\Student;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Bills')] class extends Component {
    /**
     * The guardian's own children only, each with their full bill history.
     *
     * @return Collection<int, Student>
     */
    #[Computed]
    public function children(): Collection
    {
        $guardian = auth()->user()->guardian;

        if ($guardian === null) {
            return new Collection;
        }

        return $guardian->students()
            ->with([
                'user',
                'bills' => fn ($query) => $query->orderByDesc('semester_id')->orderByDesc('id'),
                'bills.semester.academicYear',
                'bills.lines',
                'bills.payments',
            ])
            ->orderBy('nim')
            ->get();
    }
}; ?>

<section class="w-full max-w-3xl space-y-8">
    <x-page-header :title="__('Bills')" :subtitle="__('Bills and payment history of your children. Payments show as pending until the finance office verifies them.')" />

    @forelse ($this->children as $child)
        <div wire:key="child-{{ $child->id }}" class="space-y-4">
            <flux:heading size="lg">{{ $child->user->name }} <span class="font-mono text-sm text-zinc-500">{{ $child->nim }}</span></flux:heading>
            @forelse ($child->bills as $bill)
                <x-bill-card :bill="$bill" wire:key="bill-{{ $bill->id }}" />
            @empty
                <flux:text>{{ __('No bills yet.') }}</flux:text>
            @endforelse
        </div>
    @empty
        <flux:text>{{ __('No mahasantri are linked to your account. Please contact the academic office.') }}</flux:text>
    @endforelse
</section>
