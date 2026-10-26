<?php

use App\Actions\Finance\AdjustBill;
use App\Actions\Finance\RecordPayment;
use App\Actions\Finance\ReviewPayments;
use App\Enums\BillStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Models\Bill;
use App\Models\Payment;
use App\Support\ActiveRole;
use App\Support\Money;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Bill')] class extends Component {
    use WithFileUploads;

    public Bill $bill;

    // Record payment
    public ?int $amount = null;
    public string $method = 'cash';
    public ?string $paidOn = null;
    public ?string $reference = null;
    public $proof = null;

    public string $voidReason = '';
    public ?int $voidingPaymentId = null;
    public ?int $maxInstallments = null;
    public string $overrideReason = '';

    public function mount(Bill $bill): void
    {
        $this->bill = $bill;
        $this->paidOn = now()->format('Y-m-d');
    }

    #[Computed]
    public function isSuperadmin(): bool
    {
        return ActiveRole::get() === Role::Superadmin;
    }

    #[Computed]
    public function details(): Bill
    {
        return $this->bill->fresh(['student.user', 'semester.academicYear', 'lines', 'payments.recorder', 'payments.verifier', 'payments.handover']);
    }

    /**
     * @return array{open: int, used: int, max: int, min: int}
     */
    #[Computed]
    public function allowance(): array
    {
        return app(RecordPayment::class)->allowance($this->details);
    }

    public function recordPayment(RecordPayment $recordPayment): void
    {
        $this->resetErrorBag();

        $recordPayment->handle($this->bill, auth()->user(), [
            'amount' => $this->amount,
            'method' => $this->method,
            'paid_on' => $this->paidOn,
            'reference' => $this->reference ?: null,
            'proof' => $this->proof,
        ]);

        $this->reset('amount', 'reference', 'proof');
        unset($this->details, $this->allowance);
        Flux::toast(variant: 'success', text: $this->method === PaymentMethod::Cash->value
            ? __('Payment recorded. Hand the cash over to the Admin for verification.')
            : __('Payment recorded. An Admin verifies it against the bank statement.'));
    }

    public function voidBill(AdjustBill $adjustBill): void
    {
        $this->resetErrorBag();

        $adjustBill->void($this->bill, $this->voidReason);

        Flux::modal('void-bill')->close();
        unset($this->details);
        Flux::toast(variant: 'success', text: __('Bill voided. Generate bills again to bill this mahasantri.'));
    }

    public function openOverride(): void
    {
        abort_unless($this->isSuperadmin, 403);

        $this->maxInstallments = $this->details->max_installments;
        $this->overrideReason = '';
        $this->resetErrorBag();
        Flux::modal('override-installments')->show();
    }

    public function overrideInstallments(AdjustBill $adjustBill): void
    {
        abort_unless($this->isSuperadmin, 403);
        $this->resetErrorBag();

        $adjustBill->setMaxInstallments($this->bill, (int) $this->maxInstallments, $this->overrideReason);

        Flux::modal('override-installments')->close();
        unset($this->details, $this->allowance);
        Flux::toast(variant: 'success', text: __('Instalment limit changed.'));
    }

    public function openVoidPayment(int $paymentId): void
    {
        abort_unless($this->isSuperadmin, 403);

        $this->voidingPaymentId = $paymentId;
        $this->voidReason = '';
        $this->resetErrorBag();
        Flux::modal('void-payment')->show();
    }

    public function voidPayment(ReviewPayments $review): void
    {
        abort_unless($this->isSuperadmin, 403);
        $this->resetErrorBag();

        $review->void(Payment::query()->where('bill_id', $this->bill->id)->findOrFail($this->voidingPaymentId), $this->voidReason);

        Flux::modal('void-payment')->close();
        unset($this->details, $this->allowance);
        Flux::toast(variant: 'warning', text: __('Payment voided. The bill is recalculated.'));
    }
}; ?>

@php($bill = $this->details)

<section class="w-full">
    <x-page-header :title="$bill->number" :subtitle="$bill->student->user->name.' · '.$bill->student->nim.' · '.$bill->semester->label()">
        <x-slot:actions>
            <flux:badge size="lg" :color="$bill->status->color()">{{ $bill->status->label() }}</flux:badge>
            <flux:button size="sm" variant="ghost" icon="arrow-left" :href="route('finance.bills.index', ['semester' => $bill->semester_id])" wire:navigate>{{ __('Bills') }}</flux:button>
        </x-slot:actions>
    </x-page-header>

    @if ($bill->status_note)
        <flux:callout icon="information-circle" class="mb-6" :text="$bill->status_note" />
    @endif

    @foreach (['bill', 'payment'] as $key)
        @error($key)
            <flux:callout variant="danger" icon="exclamation-triangle" class="mb-6" :text="$message" />
        @enderror
    @endforeach

    <div class="grid gap-6 xl:grid-cols-[1fr_22rem]">
        <div class="space-y-8">
            <div>
                <flux:heading class="mb-2">{{ __('Bill lines') }}</flux:heading>
                <div class="rounded-lg border border-zinc-200 dark:border-zinc-700">
                    @foreach ($bill->lines as $line)
                        <div wire:key="line-{{ $line->id }}" class="flex justify-between px-4 py-2 text-sm">
                            <span>{{ $line->description }}</span>
                            <span @class(['tabular-nums', 'text-green-700 dark:text-green-400' => $line->amount < 0])>{{ Money::format($line->amount) }}</span>
                        </div>
                    @endforeach
                    <div class="flex justify-between border-t border-zinc-200 px-4 py-2 font-semibold dark:border-zinc-700">
                        <span>{{ __('Total') }}</span>
                        <span class="tabular-nums">{{ Money::format($bill->net_amount) }}</span>
                    </div>
                    <div class="flex justify-between px-4 pb-2 text-sm">
                        <span>{{ __('Paid (verified)') }}</span>
                        <span class="tabular-nums">{{ Money::format($bill->paid_amount) }}</span>
                    </div>
                    <div class="flex justify-between px-4 pb-3 text-sm font-medium">
                        <span>{{ __('Outstanding') }}</span>
                        <span class="tabular-nums">{{ Money::format($bill->status === BillStatus::Void ? 0 : $bill->outstanding()) }}</span>
                    </div>
                </div>
                <flux:text class="mt-2 text-xs">
                    {{ __('Due') }}: {{ $bill->due_on?->translatedFormat('j M Y') ?? '—' }} ·
                    {{ __('Instalments allowed') }}: {{ $bill->max_installments }}
                    @if ($this->isSuperadmin && $bill->status !== BillStatus::Void)
                        · <flux:link class="cursor-pointer" wire:click="openOverride">{{ __('change') }}</flux:link>
                    @endif
                </flux:text>
            </div>

            <div>
                <flux:heading class="mb-2">{{ __('Payments') }}</flux:heading>
                <div class="overflow-x-auto">
                    <flux:table class="table-stack">
                        <flux:table.columns>
                            <flux:table.column>{{ __('Date') }}</flux:table.column>
                            <flux:table.column>{{ __('Method') }}</flux:table.column>
                            <flux:table.column align="end">{{ __('Amount') }}</flux:table.column>
                            <flux:table.column>{{ __('Status') }}</flux:table.column>
                            <flux:table.column></flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @forelse ($bill->payments as $payment)
                                <flux:table.row :key="$payment->id">
                                    <flux:table.cell>{{ $payment->paid_on->translatedFormat('j M Y') }}</flux:table.cell>
                                    <flux:table.cell class="whitespace-normal">
                                        {{ $payment->method->label() }}
                                        @if ($payment->reference)
                                            <div class="text-xs text-zinc-500">{{ $payment->reference }}</div>
                                        @endif
                                        @if ($payment->handover)
                                            <div class="text-xs text-zinc-500">{{ $payment->handover->number }}</div>
                                        @endif
                                    </flux:table.cell>
                                    <flux:table.cell align="end" class="tabular-nums">{{ Money::format($payment->amount) }}</flux:table.cell>
                                    <flux:table.cell class="whitespace-normal">
                                        <flux:badge size="sm" :color="$payment->status->color()">{{ $payment->status->label() }}</flux:badge>
                                        <div class="text-xs text-zinc-500">
                                            {{ __('by :name', ['name' => $payment->recorder->name]) }}
                                            @if ($payment->verifier)
                                                · {{ __('reviewed by :name', ['name' => $payment->verifier->name]) }}
                                            @endif
                                        </div>
                                        @if ($payment->status_note)
                                            <div class="text-xs text-red-600 dark:text-red-400">{{ $payment->status_note }}</div>
                                        @endif
                                    </flux:table.cell>
                                    <flux:table.cell align="end">
                                        @if (in_array($payment->status, [PaymentStatus::Unverified, PaymentStatus::Verified], true))
                                            <flux:button size="sm" variant="ghost" icon="printer" :href="route('receipts.show', $payment)" target="_blank">
                                                {{ $payment->status === PaymentStatus::Verified ? __('Receipt') : __('Slip') }}
                                            </flux:button>
                                        @endif
                                        @if ($payment->proof_path)
                                            <flux:button size="sm" variant="ghost" icon="paper-clip" :href="route('files.show', ['payment-proof', $payment->id])" target="_blank" :aria-label="__('Proof')" />
                                        @endif
                                        @if ($this->isSuperadmin && $payment->status === PaymentStatus::Verified)
                                            <flux:button size="sm" variant="ghost" icon="no-symbol" wire:click="openVoidPayment({{ $payment->id }})" :aria-label="__('Void')" />
                                        @endif
                                    </flux:table.cell>
                                </flux:table.row>
                            @empty
                                <flux:table.row>
                                    <flux:table.cell colspan="5" class="text-center">{{ __('No payments yet.') }}</flux:table.cell>
                                </flux:table.row>
                            @endforelse
                        </flux:table.rows>
                    </flux:table>
                </div>
            </div>
        </div>

        <div class="space-y-6">
            @if ($bill->status !== BillStatus::Void && $this->allowance['open'] > 0 && $this->allowance['used'] < $this->allowance['max'])
                <flux:card>
                    <form wire:submit="recordPayment" class="space-y-4">
                        <flux:heading>{{ __('Record payment') }}</flux:heading>
                        <flux:text class="text-sm">
                            {{ __('Open: :open · instalment :next of :max · minimum :min unless it settles the rest.', [
                                'open' => Money::format($this->allowance['open']),
                                'next' => $this->allowance['used'] + 1,
                                'max' => $this->allowance['max'],
                                'min' => Money::format($this->allowance['min']),
                            ]) }}
                        </flux:text>
                        <flux:input wire:model="amount" :label="__('Amount (Rp)')" type="number" min="1" step="1" required />
                        <flux:radio.group wire:model.live="method" variant="segmented">
                            @foreach (PaymentMethod::cases() as $case)
                                <flux:radio :value="$case->value" :label="$case->label()" />
                            @endforeach
                        </flux:radio.group>
                        <flux:input wire:model="paidOn" :label="__('Paid on')" type="date" required />
                        @if ($method === PaymentMethod::Transfer->value)
                            <flux:input wire:model="reference" :label="__('Bank reference')" required />
                            <div>
                                <flux:label>{{ __('Proof (optional)') }}</flux:label>
                                <input type="file" wire:model="proof" accept=".pdf,.jpg,.jpeg,.png"
                                    class="mt-2 block text-sm file:me-3 file:rounded-md file:border-0 file:bg-zinc-100 file:px-3 file:py-1.5 dark:file:bg-zinc-700">
                                <flux:error name="proof" />
                            </div>
                        @endif
                        <flux:button type="submit" variant="primary" class="w-full" data-test="record-payment">{{ __('Record as unverified') }}</flux:button>
                    </form>
                </flux:card>
            @elseif ($bill->status !== BillStatus::Void)
                <flux:callout icon="check-circle" color="green" :text="$this->allowance['open'] === 0
                    ? __('Nothing left to record: the bill is paid or the rest is awaiting verification.')
                    : __('All allowed instalments are used.')" />
            @endif

            @if ($bill->status !== BillStatus::Void && ! $bill->semester->isLocked() && $bill->payments->whereIn('status', PaymentStatus::counting())->isEmpty())
                <flux:modal.trigger name="void-bill">
                    <flux:button variant="danger" size="sm" icon="no-symbol">{{ __('Void bill') }}</flux:button>
                </flux:modal.trigger>
            @endif
        </div>
    </div>

    <flux:modal name="void-bill" class="w-full max-w-md">
        <form wire:submit="voidBill" class="space-y-6">
            <flux:heading size="lg">{{ __('Void bill') }}</flux:heading>
            <flux:text>{{ __('The bill stays in the records as void. You can then generate a new bill for this mahasantri.') }}</flux:text>
            <flux:textarea wire:model="voidReason" :label="__('Reason')" rows="2" required />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="danger">{{ __('Void bill') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="override-installments" class="w-full max-w-md">
        <form wire:submit="overrideInstallments" class="space-y-6">
            <flux:heading size="lg">{{ __('Change instalment limit') }}</flux:heading>
            <flux:input wire:model="maxInstallments" :label="__('Maximum instalments for this bill')" type="number" min="1" max="12" required />
            <flux:textarea wire:model="overrideReason" :label="__('Reason')" rows="2" required />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="void-payment" class="w-full max-w-md">
        <form wire:submit="voidPayment" class="space-y-6">
            <flux:heading size="lg">{{ __('Void verified payment') }}</flux:heading>
            <flux:text>{{ __('Use this when verified money did not actually arrive (e.g. a reversed transfer). The receipt becomes void and the bill is recalculated.') }}</flux:text>
            <flux:textarea wire:model="voidReason" :label="__('Reason')" rows="2" required />
            <flux:error name="payment" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="danger">{{ __('Void payment') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
