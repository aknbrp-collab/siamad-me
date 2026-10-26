<?php

use App\Actions\Finance\ReviewPayments;
use App\Enums\PaymentMethod;
use App\Enums\PaymentSource;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Support\Money;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Transfer confirmations')] class extends Component {
    public ?int $rejectingId = null;
    public string $rejectionReason = '';

    /**
     * Transfers sent in by mahasantri and wali santri, waiting for a check.
     *
     * @return Collection<int, Payment>
     */
    #[Computed]
    public function waiting(): Collection
    {
        return Payment::query()
            ->with(['bill.student.user', 'bill.semester.academicYear', 'recorder'])
            ->where('method', PaymentMethod::Transfer)
            ->where('source', PaymentSource::Payer)
            ->where('status', PaymentStatus::Unverified)
            ->orderBy('created_at')
            ->get();
    }

    /**
     * The latest ones already checked, for reference.
     *
     * @return Collection<int, Payment>
     */
    #[Computed]
    public function reviewed(): Collection
    {
        return Payment::query()
            ->with(['bill.student.user', 'verifier'])
            ->where('source', PaymentSource::Payer)
            ->whereIn('status', [PaymentStatus::Verified, PaymentStatus::Rejected])
            ->latest('verified_at')
            ->limit(10)
            ->get();
    }

    public function verify(int $id, ReviewPayments $review): void
    {
        $this->resetErrorBag();

        $review->verifyTransfer($this->payment($id), auth()->user());

        unset($this->waiting, $this->reviewed);
        Flux::toast(variant: 'success', text: __('Transfer verified.'));
    }

    public function startReject(int $id): void
    {
        $this->resetErrorBag();
        $this->rejectingId = $this->payment($id)->id;
        $this->rejectionReason = '';
        Flux::modal('reject-confirmation')->show();
    }

    public function reject(ReviewPayments $review): void
    {
        $this->resetErrorBag();

        $review->rejectTransfer($this->payment((int) $this->rejectingId), auth()->user(), $this->rejectionReason);

        Flux::modal('reject-confirmation')->close();
        unset($this->waiting, $this->reviewed);
        Flux::toast(variant: 'warning', text: __('Transfer rejected. The sender sees your reason.'));
    }

    /**
     * Only payer-sent transfers are handled on this page.
     */
    private function payment(int $id): Payment
    {
        return Payment::query()->where('source', PaymentSource::Payer)->findOrFail($id);
    }
}; ?>

<section class="w-full">
    <x-page-header :title="__('Transfer confirmations')"
        :subtitle="__('Transfers sent in by mahasantri and wali santri with their bank receipt. Check each against the bank statement, then verify or reject it.')" />

    @error('payment')
        <flux:callout variant="danger" icon="exclamation-triangle" class="mb-4" :text="$message" />
    @enderror

    <div class="overflow-x-auto">
        <flux:table class="table-stack">
            <flux:table.columns>
                <flux:table.column>{{ __('Mahasantri') }}</flux:table.column>
                <flux:table.column>{{ __('Reference') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Amount') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->waiting as $payment)
                    <flux:table.row :key="$payment->id">
                        <flux:table.cell class="whitespace-normal">
                            <span class="font-medium">{{ $payment->bill->student->user->name }}</span>
                            <div class="text-xs text-zinc-500">
                                {{ $payment->bill->number }} · {{ $payment->bill->semester->label() }} · {{ __('sent by :name', ['name' => $payment->recorder->name]) }}
                            </div>
                        </flux:table.cell>
                        <flux:table.cell class="whitespace-normal">
                            {{ $payment->reference }}
                            <div class="text-xs text-zinc-500">{{ $payment->paid_on->translatedFormat('j M Y') }}</div>
                        </flux:table.cell>
                        <flux:table.cell align="end" class="tabular-nums">{{ Money::format($payment->amount) }}</flux:table.cell>
                        <flux:table.cell align="end">
                            <flux:button size="sm" variant="ghost" icon="paper-clip" :href="route('files.show', ['payment-proof', $payment->id])" target="_blank">{{ __('Bank receipt') }}</flux:button>
                            <flux:button size="sm" variant="primary" icon="check" wire:click="verify({{ $payment->id }})">{{ __('Verify') }}</flux:button>
                            <flux:button size="sm" variant="danger" icon="x-mark" wire:click="startReject({{ $payment->id }})">{{ __('Reject') }}</flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="4" class="text-center">{{ __('No transfer confirmations waiting.') }}</flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>

    @if ($this->reviewed->isNotEmpty())
        <flux:heading class="mt-10 mb-3">{{ __('Recently checked') }}</flux:heading>
        <div class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 text-sm dark:divide-zinc-700 dark:border-zinc-700">
            @foreach ($this->reviewed as $payment)
                <div wire:key="reviewed-{{ $payment->id }}" class="flex flex-wrap items-center justify-between gap-2 px-3 py-2">
                    <span>{{ $payment->bill->student->user->name }} · <span class="tabular-nums">{{ Money::format($payment->amount) }}</span></span>
                    <span class="flex items-center gap-2 text-zinc-500">
                        <flux:badge size="sm" :color="$payment->status->color()">{{ $payment->status->label() }}</flux:badge>
                        {{ __('by :name', ['name' => $payment->verifier?->name ?? '—']) }}
                    </span>
                </div>
            @endforeach
        </div>
    @endif

    <flux:modal name="reject-confirmation" class="w-full max-w-md">
        <form wire:submit="reject" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Reject transfer') }}</flux:heading>
                <flux:text class="mt-1">{{ __('The sender sees this reason and can send the transfer again.') }}</flux:text>
            </div>
            <flux:textarea wire:model="rejectionReason" :label="__('Reason')" :placeholder="__('e.g. Not found on the bank statement')" rows="2" required />
            <flux:error name="rejectionReason" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="danger">{{ __('Reject') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
