<?php

use App\Actions\Finance\ReviewPayments;
use App\Enums\PaymentMethod;
use App\Enums\PaymentSource;
use App\Enums\PaymentStatus;
use App\Enums\HandoverStatus;
use App\Models\Payment;
use App\Models\PaymentHandover;
use App\Support\Money;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Transfer confirmations')] class extends Component {
    /** Not recorded by the finance office: Admin Keuangan may verify these (v1 D3, v2 D1). */
    private const SOURCES = [PaymentSource::Payer, PaymentSource::Collector];

    public ?int $rejectingId = null;
    public string $rejectionReason = '';

    public ?int $reviewingId = null;

    /** @var array<int|string, bool> Payment id => rejected? (within the handover being counted) */
    public array $rejected = [];

    /** @var array<int|string, string> */
    public array $reasons = [];

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
            ->whereIn('source', self::SOURCES)
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
            ->whereIn('source', self::SOURCES)
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
     * Cash handed over by a Petugas Pembayaran, waiting to be counted.
     *
     * @return Collection<int, PaymentHandover>
     */
    #[Computed]
    public function handovers(): Collection
    {
        return PaymentHandover::query()
            ->with(['handedOverBy', 'payments.bill.student.user'])
            ->where('status', HandoverStatus::Submitted)
            ->whereHas('payments', fn ($query) => $query->where('source', PaymentSource::Collector))
            ->orderBy('submitted_at')
            ->get();
    }

    #[Computed]
    public function reviewing(): ?PaymentHandover
    {
        return $this->handovers->firstWhere('id', $this->reviewingId);
    }

    #[Computed]
    public function acceptedTotal(): int
    {
        return (int) ($this->reviewing?->payments
            ->reject(fn (Payment $payment) => $this->rejected[$payment->id] ?? false)
            ->sum('amount') ?? 0);
    }

    public function openHandover(int $id): void
    {
        $this->reviewingId = $id;
        $this->rejected = [];
        $this->reasons = [];
        $this->resetErrorBag();
        Flux::modal('count-handover')->show();
    }

    public function completeHandover(ReviewPayments $review): void
    {
        $this->resetErrorBag();
        abort_if($this->reviewing === null, 404);

        $rejections = [];
        foreach ($this->rejected as $paymentId => $isRejected) {
            if ($isRejected) {
                $rejections[(int) $paymentId] = (string) ($this->reasons[$paymentId] ?? '');
            }
        }

        $review->completeHandover($this->reviewing, auth()->user(), $rejections);

        Flux::modal('count-handover')->close();
        unset($this->handovers, $this->reviewing, $this->reviewed);
        Flux::toast(variant: 'success', text: __('Handover reviewed. Verified payments now count and have a receipt.'));
    }

    /**
     * Only transfers not recorded by the finance office are handled on this page.
     */
    private function payment(int $id): Payment
    {
        return Payment::query()->whereIn('source', self::SOURCES)->findOrFail($id);
    }
}; ?>

<section class="w-full">
    <x-page-header :title="__('Transfer confirmations')"
        :subtitle="__('Transfers sent in by mahasantri and wali santri with their bank receipt, and payments recorded by a payment collector. Check each one, then verify or reject it.')" />

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
                                @if ($payment->source === PaymentSource::Collector)
                                    <flux:badge size="sm" color="purple" class="ms-1">{{ __('Payment collector') }}</flux:badge>
                                @endif
                            </div>
                        </flux:table.cell>
                        <flux:table.cell class="whitespace-normal">
                            {{ $payment->reference }}
                            <div class="text-xs text-zinc-500">{{ $payment->paid_on->translatedFormat('j M Y') }}</div>
                        </flux:table.cell>
                        <flux:table.cell align="end" class="tabular-nums">{{ Money::format($payment->amount) }}</flux:table.cell>
                        <flux:table.cell align="end">
                            @if ($payment->proof_path)
                                <flux:button size="sm" variant="ghost" icon="paper-clip" :href="route('files.show', ['payment-proof', $payment->id])" target="_blank">{{ __('Bank receipt') }}</flux:button>
                            @endif
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

    <flux:heading class="mt-10 mb-3">{{ __('Cash from payment collectors') }}</flux:heading>
    <div class="space-y-3">
        @forelse ($this->handovers as $handover)
            <flux:card wire:key="handover-{{ $handover->id }}" class="flex flex-wrap items-center gap-3">
                <div class="me-auto">
                    <div class="font-medium">{{ __('Handover :number', ['number' => $handover->number]) }}</div>
                    <flux:text class="text-sm">{{ $handover->handedOverBy->name }} · {{ trans_choice(':count payment|:count payments', $handover->payments->count()) }}</flux:text>
                </div>
                <span class="text-lg font-semibold tabular-nums">{{ Money::format($handover->total_amount) }}</span>
                <flux:button variant="primary" wire:click="openHandover({{ $handover->id }})">{{ __('Count & verify') }}</flux:button>
            </flux:card>
        @empty
            <flux:text class="text-sm">{{ __('No cash handovers waiting.') }}</flux:text>
        @endforelse
    </div>
    @error('handover')
        <flux:callout variant="danger" icon="exclamation-triangle" class="mt-3" :text="$message" />
    @enderror

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

    <flux:modal name="count-handover" class="w-full max-w-2xl">
        @if ($this->reviewing)
            <form wire:submit="completeHandover" class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Handover :number', ['number' => $this->reviewing->number]) }}</flux:heading>
                    <flux:text class="mt-1">{{ __('Count the cash. Tick a payment only if its money is missing; it will be rejected with your reason.') }}</flux:text>
                </div>

                <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @foreach ($this->reviewing->payments as $payment)
                        <div wire:key="count-{{ $payment->id }}" class="space-y-2 py-2">
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
                    <flux:button type="submit" variant="primary" data-test="count-handover">{{ __('Confirm') }}</flux:button>
                </div>
            </form>
        @endif
    </flux:modal>

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
