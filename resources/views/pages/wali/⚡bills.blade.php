<?php

use App\Actions\Exams\ManageDispensationRequests;
use App\Livewire\BillsPage;
use App\Models\DispensationRequest;
use App\Models\Student;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;

new #[Title('Bills')] class extends BillsPage {
    protected function refreshBills(): void
    {
        unset($this->children);
    }

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

    /**
     * Each child's dispensation request of the active semester, and whether it is granted (v7 D47).
     *
     * @return array<int, array{request: DispensationRequest, granted: bool}>
     */
    #[Computed]
    public function dispensations(): array
    {
        $requests = app(ManageDispensationRequests::class);

        return $this->children
            ->mapWithKeys(fn (Student $child) => [$child->id => $requests->current($child)?->load('semester.academicYear')])
            ->filter()
            ->map(fn (DispensationRequest $request) => ['request' => $request, 'granted' => $requests->granted($request)])
            ->all();
    }
}; ?>

<section class="w-full max-w-3xl space-y-8">
    <x-page-header :title="__('Bills')" :subtitle="__('Bills and payment history of your children. Payments show as pending until the finance office verifies them.')" />

    @forelse ($this->children as $child)
        <div wire:key="child-{{ $child->id }}" class="space-y-4">
            <flux:heading size="lg">{{ $child->user->name }} <span class="font-mono text-sm text-zinc-500">{{ $child->nim }}</span></flux:heading>
            @isset($this->dispensations[$child->id])
                @include('partials.dispensation-card', ['request' => $this->dispensations[$child->id]['request'], 'granted' => $this->dispensations[$child->id]['granted'], 'canRequest' => false, 'editable' => false])
            @endisset
            @forelse ($child->bills as $bill)
                <x-bill-card :bill="$bill" confirmable wire:key="bill-{{ $bill->id }}" />
            @empty
                <flux:text>{{ __('No bills yet.') }}</flux:text>
            @endforelse
        </div>
    @empty
        <flux:text>{{ __('No mahasantri are linked to your account. Please contact the academic office.') }}</flux:text>
    @endforelse

    @include('partials.confirm-transfer-modal')
</section>
