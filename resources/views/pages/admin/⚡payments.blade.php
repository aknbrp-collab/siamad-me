<?php

use App\Actions\Finance\ReviewPayments;
use App\Enums\HandoverStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\PaymentHandover;
use App\Support\Money;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Payment verification')] class extends Component {
    #[Url]
    public string $tab = 'handovers';

    public ?int $reviewingId = null;

    /** @var array<int|string, bool> Payment id => rejected? (within the handover being reviewed) */
    public array $rejected = [];

    /** @var array<int|string, string> */
    public array $reasons = [];

    public ?int $rejectingTransferId = null;
    public string $rejectionReason = '';

    /**
     * @return Collection<int, PaymentHandover>
     */
    #[Computed]
    public function handovers(): Collection
    {
        return PaymentHandover::query()
            ->with(['handedOverBy', 'payments.bill.student.user'])
            ->where('status', HandoverStatus::Submitted)
            ->orderBy('submitted_at')
            ->get();
    }

    #[Computed]
    public function reviewing(): ?PaymentHandover
    {
        return $this->handovers->firstWhere('id', $this->reviewingId);
    }

    /**
     * @return Collection<int, Payment>
     */
    #[Computed]
    public function transfers(): Collection
    {
        return Payment::query()
            ->with(['bill.student.user', 'recorder'])
            ->where('method', PaymentMethod::Transfer)
            ->where('status', PaymentStatus::Unverified)
            ->orderBy('paid_on')
            ->get();
    }

    public function openHandover(int $id): void
    {
        $this->reviewingId = $id;
        $this->rejected = [];
        $this->reasons = [];
        $this->resetErrorBag();
        Flux::modal('review-handover')->show();
    }

    #[Computed]
    public function acceptedTotal(): int
    {
        return (int) ($this->reviewing?->payments
            ->reject(fn (Payment $payment) => $this->rejected[$payment->id] ?? false)
            ->sum('amount') ?? 0);
    }

    public function completeHandover(ReviewPayments $review): void
    {
        $this->resetErrorBag();

        $rejections = collect($this->rejected)
            ->filter()
            ->mapWithKeys(fn ($flag, $id) => [(int) $id => (string) ($this->reasons[$id] ?? '')])
            ->all();

        $review->completeHandover(PaymentHandover::query()->findOrFail($this->reviewingId), auth()->user(), $rejections);

        Flux::modal('review-handover')->close();
        unset($this->handovers);
        Flux::toast(variant: 'success', text: __('Handover reviewed. Verified payments now count and have a receipt.'));
    }

    public function verifyTransfer(int $id, ReviewPayments $review): void
    {
        $this->resetErrorBag();

        $review->verifyTransfer(Payment::query()->findOrFail($id), auth()->user());

        unset($this->transfers);
        Flux::toast(variant: 'success', text: __('Transfer verified.'));
    }

    public function startRejectTransfer(int $id): void
    {
        $this->rejectingTransferId = $id;
        $this->rejectionReason = '';
        $this->resetErrorBag();
        Flux::modal('reject-transfer')->show();
    }

    public function rejectTransfer(ReviewPayments $review): void
    {
        $this->resetErrorBag();

        $review->rejectTransfer(Payment::query()->findOrFail($this->rejectingTransferId), auth()->user(), $this->rejectionReason);

        Flux::modal('reject-transfer')->close();
        unset($this->transfers);
        Flux::toast(variant: 'success', text: __('Transfer rejected.'));
    }
}; ?>

<section class="w-full">
    <x-page-header :title="__('Payment verification')" :subtitle="__('Confirm the money actually arrived. You cannot verify payments you recorded yourself.')" />

    @error('payment')
        <flux:callout variant="danger" icon="exclamation-triangle" class="mb-6" :text="$message" />
    @enderror

    <flux:radio.group wire:model.live="tab" variant="segmented" class="mb-6">
        <flux:radio value="handovers" :label="__('Cash handovers (:count)', ['count' => $this->handovers->count()])" />
        <flux:radio value="transfers" :label="__('Transfers (:count)', ['count' => $this->transfers->count()])" />
    </flux:radio.group>

    @if ($tab === 'handovers')
        <div class="flex flex-col gap-3">
            @forelse ($this->handovers as $handover)
                <flux:card wire:key="handover-{{ $handover->id }}" class="flex flex-col gap-3 sm:flex-row sm:items-center">
                    <div class="flex-1">
                        <div class="font-mono text-sm">{{ $handover->number }}</div>
                        <flux:text class="text-sm">
                            {{ __('From :name', ['name' => $handover->handedOverBy->name]) }} ·
                            {{ $handover->submitted_at->diffForHumans() }} ·
                            {{ trans_choice(':count payment|:count payments', $handover->payments->count()) }}
                        </flux:text>
                    </div>
                    <span class="text-lg font-semibold tabular-nums">{{ Money::format($handover->total_amount) }}</span>
                    <flux:button variant="primary" wire:click="openHandover({{ $handover->id }})">{{ __('Count & verify') }}</flux:button>
                </flux:card>
            @empty
                <flux:text class="py-8 text-center">{{ __('No cash handovers waiting.') }}</flux:text>
            @endforelse
        </div>
    @else
        <div class="overflow-x-auto">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Mahasantri') }}</flux:table.column>
                    <flux:table.column>{{ __('Reference') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Amount') }}</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse ($this->transfers as $payment)
                        <flux:table.row :key="$payment->id">
                            <flux:table.cell class="whitespace-normal">
                                <span class="font-medium">{{ $payment->bill->student->user->name }}</span>
                                <div class="text-xs text-zinc-500">{{ $payment->bill->number }} · {{ __('recorded by :name', ['name' => $payment->recorder->name]) }}</div>
                            </flux:table.cell>
                            <flux:table.cell class="whitespace-normal">
                                {{ $payment->reference }}
                                <div class="text-xs text-zinc-500">{{ $payment->paid_on->translatedFormat('j M Y') }}</div>
                            </flux:table.cell>
                            <flux:table.cell align="end" class="tabular-nums">{{ Money::format($payment->amount) }}</flux:table.cell>
                            <flux:table.cell align="end">
                                @if ($payment->proof_path)
                                    <flux:button size="sm" variant="ghost" icon="paper-clip" :href="route('files.show', ['payment-proof', $payment->id])" target="_blank">{{ __('Proof') }}</flux:button>
                                @endif
                                <flux:button size="sm" variant="primary" icon="check" wire:click="verifyTransfer({{ $payment->id }})">{{ __('Verify') }}</flux:button>
                                <flux:button size="sm" variant="danger" icon="x-mark" wire:click="startRejectTransfer({{ $payment->id }})">{{ __('Reject') }}</flux:button>
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="4" class="text-center">{{ __('No transfers waiting.') }}</flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </div>
    @endif

    <flux:modal name="review-handover" class="w-full max-w-2xl">
        @if ($this->reviewing)
            <form wire:submit="completeHandover" class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Handover :number', ['number' => $this->reviewing->number]) }}</flux:heading>
                    <flux:text class="mt-1">{{ __('Count the cash. Tick a payment only if its money is missing; it will be rejected with your reason.') }}</flux:text>
                </div>

                <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @foreach ($this->reviewing->payments as $payment)
                        <div wire:key="review-{{ $payment->id }}" class="space-y-2 py-2">
                            <div class="flex items-center gap-3 text-sm">
                                <flux:checkbox wire:model.live="rejected.{{ $payment->id }}" :label="__('Reject')" />
                                <span class="flex-1">
                                    <span class="font-medium">{{ $payment->bill->student->user->name }}</span>
                                    <span class="text-zinc-500">· {{ $payment->bill->number }}</span>
                                </span>
                                <span class="tabular-nums">{{ Money::format($payment->amount) }}</span>
                            </div>
                            @if ($rejected[$payment->id] ?? false)
                                <flux:input wire:model="reasons.{{ $payment->id }}" size="sm" :placeholder="__('Reason, e.g. money not received')" />
                            @endif
                        </div>
                    @endforeach
                </div>

                <div class="flex justify-between rounded-lg bg-zinc-50 p-3 font-medium dark:bg-zinc-800">
                    <span>{{ __('Received and verified') }}</span>
                    <span class="tabular-nums">{{ Money::format($this->acceptedTotal) }} / {{ Money::format($this->reviewing->total_amount) }}</span>
                </div>

                @error('handover')
                    <flux:callout variant="danger" icon="exclamation-triangle" :text="$message" />
                @enderror

                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary" data-test="complete-handover">{{ __('Confirm') }}</flux:button>
                </div>
            </form>
        @endif
    </flux:modal>

    <flux:modal name="reject-transfer" class="w-full max-w-md">
        <form wire:submit="rejectTransfer" class="space-y-6">
            <flux:heading size="lg">{{ __('Reject transfer') }}</flux:heading>
            <flux:textarea wire:model="rejectionReason" :label="__('Reason')" :placeholder="__('e.g. Not found on the bank statement')" rows="2" required />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="danger">{{ __('Reject') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
