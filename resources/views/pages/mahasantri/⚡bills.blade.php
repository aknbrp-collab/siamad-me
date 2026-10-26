<?php

use App\Models\Bill;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('My bills')] class extends Component {
    /**
     * Every bill of every semester, newest first (history stays visible).
     *
     * @return Collection<int, Bill>
     */
    #[Computed]
    public function bills(): Collection
    {
        $student = auth()->user()->student;

        if ($student === null) {
            return new Collection;
        }

        return $student->bills()
            ->with(['semester.academicYear', 'lines', 'payments'])
            ->orderByDesc('semester_id')
            ->orderByDesc('id')
            ->get();
    }
}; ?>

<section class="w-full max-w-3xl">
    <x-page-header :title="__('My bills')" :subtitle="__('Payments show as pending until the finance office verifies them. Verified payments have an official receipt.')" />

    <div class="flex flex-col gap-4">
        @forelse ($this->bills as $bill)
            <x-bill-card :bill="$bill" wire:key="bill-{{ $bill->id }}" />
        @empty
            <flux:text>{{ __('No bills yet.') }}</flux:text>
        @endforelse
    </div>
</section>
