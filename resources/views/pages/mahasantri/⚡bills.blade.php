<?php

use App\Actions\Exams\ManageDispensationRequests;
use App\Livewire\BillsPage;
use App\Models\Bill;
use App\Models\DispensationRequest;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;

new #[Title('My bills')] class extends BillsPage {
    public string $dispensationReason = '';

    protected function refreshBills(): void
    {
        unset($this->bills, $this->dispensation, $this->canRequestDispensation);
    }

    /**
     * The dispensation request of the active semester (v7 D47).
     */
    #[Computed]
    public function dispensation(): ?DispensationRequest
    {
        $student = auth()->user()->student;

        return $student ? app(ManageDispensationRequests::class)->current($student)?->load('semester.academicYear') : null;
    }

    #[Computed]
    public function canRequestDispensation(): bool
    {
        $student = auth()->user()->student;

        return $student !== null && app(ManageDispensationRequests::class)->openSemester($student) !== null;
    }

    #[Computed]
    public function dispensationGranted(): bool
    {
        return $this->dispensation !== null && app(ManageDispensationRequests::class)->granted($this->dispensation);
    }

    public function writeDispensation(): void
    {
        $this->dispensationReason = (string) $this->dispensation?->reason;
        $this->resetErrorBag();
        Flux::modal('dispensation-form')->show();
    }

    public function saveDispensation(ManageDispensationRequests $requests): void
    {
        $student = auth()->user()->student;
        abort_if($student === null, 403);

        $requests->submit($student, $this->dispensationReason, auth()->user());

        unset($this->dispensation, $this->dispensationGranted);
        Flux::modal('dispensation-form')->close();
        Flux::toast(variant: 'success', text: __('Request saved. Print the letter and have it signed by the Mudir.'));
    }

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
        @include('partials.dispensation-card', ['request' => $this->dispensation, 'granted' => $this->dispensationGranted, 'canRequest' => $this->canRequestDispensation, 'editable' => true])

        @forelse ($this->bills as $bill)
            <x-bill-card :bill="$bill" confirmable wire:key="bill-{{ $bill->id }}" />
        @empty
            <flux:text>{{ __('No bills yet.') }}</flux:text>
        @endforelse
    </div>

    @include('partials.confirm-transfer-modal')

    <flux:modal name="dispensation-form" class="w-full max-w-lg">
        <form wire:submit="saveDispensation" class="space-y-5">
            <div>
                <flux:heading size="lg">{{ __('Dispensation request') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Explain to the Mudir why the bill is not paid off yet and when you expect to pay. The letter lists your bills; print it, have it signed, and hand it to the academic office.') }}</flux:text>
            </div>
            <flux:textarea wire:model="dispensationReason" :label="__('Reason')" rows="6" maxlength="{{ ManageDispensationRequests::MAX_REASON }}" required data-test="dispensation-reason" />
            <flux:error name="reason" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="save-dispensation">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
