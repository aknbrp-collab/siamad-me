<?php

use App\Enums\PrintDocument;
use App\Actions\Finance\AdjustBill;
use App\Actions\Finance\RecordPayment;
use App\Actions\Finance\ReviewPayments;
use App\Enums\BillStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Models\Bill;
use App\Models\BillLine;
use App\Models\CashAccount;
use App\Models\Payment;
use App\Support\ActiveRole;
use App\Support\BillAccess;
use App\Support\CashBook;
use App\Support\Money;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Bill')] class extends Component {
    use WithFileUploads;

    public Bill $bill;

    // Record payment: the fees ticked, with an amount (and, for a monthly fee, months) each (after-review D57).
    /** @var array<int|string, bool> bill line id => ticked */
    public array $pay = [];

    /** @var array<int|string, int|string|null> bill line id => amount */
    public array $feeAmounts = [];

    /** @var array<int|string, int|string|null> bill line id => months (monthly fees) */
    public array $feeMonths = [];

    public string $method = 'cash';
    public ?string $paidOn = null;
    public ?string $reference = null;
    public $proof = null;

    /** Account the money goes into (cash book); the default for the method is preselected. */
    public ?int $cashAccountId = null;

    public string $voidReason = '';
    public ?int $voidingPaymentId = null;
    public ?int $maxInstallments = null;
    public string $overrideReason = '';

    /** The fee whose limit is changed; null for every fee of the bill. */
    public ?int $overrideLineId = null;

    public function mount(Bill $bill): void
    {
        // A Petugas Pembayaran only opens bills of their own scope (putra or putri).
        abort_unless(BillAccess::allows(auth()->user(), ActiveRole::get(), $bill), 403);

        $this->bill = $bill;
        $this->paidOn = now()->format('Y-m-d');
        $this->cashAccountId = CashBook::defaultAccountId(PaymentMethod::Cash);
    }

    public function updatedMethod(): void
    {
        $this->cashAccountId = CashBook::defaultAccountId(PaymentMethod::from($this->method));
    }

    /**
     * @return Collection<int, CashAccount>
     */
    #[Computed]
    public function cashAccounts(): Collection
    {
        return CashBook::accountsFor(PaymentMethod::from($this->method));
    }

    #[Computed]
    public function isSuperadmin(): bool
    {
        return ActiveRole::get() === Role::Superadmin;
    }

    #[Computed]
    public function details(): Bill
    {
        return $this->bill->fresh(['student.user', 'semester.academicYear', 'lines', 'payments.recorder', 'payments.verifier', 'payments.handover', 'payments.allocations.line']);
    }

    /**
     * @return array{open: int, min: int, fees: list<array{id: int, description: string, due: int, paid: int, pending: int, left: int, used: int, max: int, months: int|null}>}
     */
    #[Computed]
    public function allowance(): array
    {
        return app(RecordPayment::class)->allowance($this->details);
    }

    /**
     * The fees as the form uses them, keyed by bill line id: can it take a payment, and for a monthly fee the
     * monthly amount and the months not yet paid or waiting.
     *
     * @return array<int, array{id: int, description: string, due: int, paid: int, pending: int, left: int, used: int, max: int, months: int|null, payable: bool, monthly: int|null, monthsLeft: int|null}>
     */
    #[Computed]
    public function fees(): array
    {
        $fees = [];

        foreach ($this->allowance['fees'] as $fee) {
            $monthly = $fee['months'] ? intdiv($fee['due'], $fee['months']) : 0;
            $fees[$fee['id']] = [
                ...$fee,
                'payable' => $fee['left'] > 0 && $fee['used'] < $fee['max'],
                'monthly' => $monthly > 0 ? $monthly : null,
                'monthsLeft' => $monthly > 0 ? (int) ceil($fee['left'] / $monthly) : null,
            ];
        }

        return $fees;
    }

    /**
     * The sum of the ticked fees' amounts: the payment's Jumlah.
     */
    #[Computed]
    public function total(): int
    {
        return array_sum(array_map(fn (int|string $id) => max(0, (int) ($this->feeAmounts[$id] ?? 0)), array_keys(array_filter($this->pay))));
    }

    /**
     * Ticking a fee fills its amount with what is left of it (and its months left); unticking clears it. Livewire
     * sends one fee ($key) or, when ticks are sent together, the whole list.
     */
    public function updatedPay(mixed $value, ?string $key = null): void
    {
        if ($key === null) {
            foreach (array_keys($this->fees) as $id) {
                $ticked = ! empty($this->pay[$id]);
                if (! $ticked || ! isset($this->feeAmounts[$id])) {
                    $this->tick((string) $id, $ticked);
                }
            }

            return;
        }

        $this->tick($key, (bool) $value);
    }

    private function tick(string $key, bool $ticked): void
    {
        $fee = $this->fees[(int) $key] ?? null;

        if (! $ticked || $fee === null || ! $fee['payable']) {
            unset($this->pay[$key], $this->feeAmounts[$key], $this->feeMonths[$key]);

            return;
        }

        $this->feeAmounts[$key] = $fee['left'];
        $this->feeMonths[$key] = $fee['monthsLeft'];
    }

    /**
     * A monthly fee set by months: that many months of its monthly amount, the rest of the fee for its last months.
     */
    public function updatedFeeMonths(mixed $value, ?string $key = null): void
    {
        $fee = $key !== null ? $this->fees[(int) $key] ?? null : null;

        if ($fee === null || $fee['monthly'] === null) {
            return;
        }

        $months = max(1, min((int) $value, (int) $fee['monthsLeft']));
        $this->feeMonths[$key] = $months;
        $this->feeAmounts[$key] = $months >= $fee['monthsLeft'] ? $fee['left'] : min($fee['left'], $months * $fee['monthly']);
    }

    #[Computed]
    public function administers(): bool
    {
        return BillAccess::administers(ActiveRole::get());
    }

    public function recordPayment(RecordPayment $recordPayment): void
    {
        abort_unless(BillAccess::allows(auth()->user(), ActiveRole::get(), $this->bill), 403);

        $this->resetErrorBag();

        $allocations = [];
        foreach (array_keys(array_filter($this->pay)) as $id) {
            $allocations[(int) $id] = (int) ($this->feeAmounts[$id] ?? 0);
        }

        if ($allocations === []) {
            $this->addError('allocations', __('Choose at least one fee to pay.'));

            return;
        }

        $recordPayment->handle($this->bill, auth()->user(), [
            'allocations' => $allocations,
            'method' => $this->method,
            'paid_on' => $this->paidOn,
            'reference' => $this->reference ?: null,
            'proof' => $this->proof,
            // A Petugas Pembayaran hands cash over; the receiving Admin books it.
            'cash_account_id' => $this->administers ? $this->cashAccountId : null,
        ], BillAccess::source(ActiveRole::get()));

        $this->reset('pay', 'feeAmounts', 'feeMonths', 'reference', 'proof');
        unset($this->details, $this->allowance, $this->fees);
        Flux::toast(variant: 'success', text: $this->method === PaymentMethod::Cash->value
            ? __('Payment recorded. Hand the cash over to the Admin for verification.')
            : __('Payment recorded. An Admin verifies it against the bank statement.'));
    }

    public function voidBill(AdjustBill $adjustBill): void
    {
        abort_unless($this->administers, 403);

        $this->resetErrorBag();

        $adjustBill->void($this->bill, $this->voidReason);

        Flux::modal('void-bill')->close();
        unset($this->details);
        Flux::toast(variant: 'success', text: __('Bill voided. Generate bills again to bill this mahasantri.'));
    }

    public function openOverride(?int $lineId = null): void
    {
        abort_unless($this->isSuperadmin, 403);

        $this->overrideLineId = $lineId !== null && isset($this->fees[$lineId]) ? $lineId : null;
        $this->maxInstallments = $this->overrideLineId !== null ? $this->fees[$this->overrideLineId]['max'] : $this->details->max_installments;
        $this->overrideReason = '';
        $this->resetErrorBag();
        Flux::modal('override-installments')->show();
    }

    public function overrideInstallments(AdjustBill $adjustBill): void
    {
        abort_unless($this->isSuperadmin, 403);
        $this->resetErrorBag();

        $line = $this->overrideLineId !== null ? BillLine::query()->where('bill_id', $this->bill->id)->findOrFail($this->overrideLineId) : null;
        $adjustBill->setMaxInstallments($this->bill, (int) $this->maxInstallments, $this->overrideReason, $line);

        Flux::modal('override-installments')->close();
        unset($this->details, $this->allowance, $this->fees);
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
        unset($this->details, $this->allowance, $this->fees);
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
                        @php($fee = $this->fees[$line->id] ?? null)
                        <div wire:key="line-{{ $line->id }}" class="flex justify-between gap-3 px-4 py-2 text-sm" data-test="bill-line">
                            <div>
                                <span>{{ $line->description }}</span>
                                @if ($fee && $bill->status !== BillStatus::Void)
                                    <div class="text-xs text-zinc-500">
                                        {{ __('Left :left · instalments :used of :max', ['left' => Money::format($fee['left']), 'used' => $fee['used'], 'max' => $fee['max']]) }}
                                        @if ($this->isSuperadmin)
                                            · <flux:link class="cursor-pointer" wire:click="openOverride({{ $line->id }})" data-test="fee-limit">{{ __('change') }}</flux:link>
                                        @endif
                                    </div>
                                @endif
                            </div>
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
                    @if ($this->isSuperadmin && $bill->status !== BillStatus::Void)
                        <flux:link class="cursor-pointer" wire:click="openOverride">{{ __('Change the instalment limit of every fee') }}</flux:link>
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
                                    <flux:table.cell align="end" class="whitespace-normal tabular-nums">
                                        {{ Money::format($payment->amount) }}
                                        @foreach ($payment->allocations->sortBy('bill_line_id') as $allocation)
                                            <div class="text-xs text-zinc-500" data-test="payment-fee">{{ $allocation->line->description }}: {{ Money::format($allocation->amount) }}</div>
                                        @endforeach
                                    </flux:table.cell>
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
                                            <x-print-button size="sm" variant="ghost" icon="printer" :href="route('receipts.show', $payment)" :document="PrintDocument::Receipt">
                                                {{ $payment->status === PaymentStatus::Verified ? __('Receipt') : __('Slip') }}
                                            </x-print-button>
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
            @if ($bill->status !== BillStatus::Void && $this->allowance['open'] > 0)
                <flux:card>
                    <form wire:submit="recordPayment" class="space-y-4">
                        <flux:heading>{{ __('Record payment') }}</flux:heading>
                        <flux:text class="text-sm">
                            {{ __('Tick the fees paid. A fee paid in part needs at least :min unless that settles it or pays whole months of a monthly fee; its last allowed instalment must settle it.', [
                                'min' => Money::format($this->allowance['min']),
                            ]) }}
                        </flux:text>
                        <div class="space-y-3" data-test="payment-fees">
                            @foreach ($this->fees as $id => $fee)
                                <div wire:key="pay-{{ $id }}" @class(['rounded-lg border border-zinc-200 p-3 dark:border-zinc-700', 'opacity-60' => ! $fee['payable']])>
                                    <flux:checkbox wire:model.live="pay.{{ $id }}" :disabled="! $fee['payable']" :label="$fee['description']"
                                        :description="$fee['payable']
                                            ? __('Left :left · instalments :used of :max', ['left' => Money::format($fee['left']), 'used' => $fee['used'], 'max' => $fee['max']])
                                            : ($fee['left'] === 0 ? __('Paid or awaiting verification') : __('All allowed instalments are used.'))" />
                                    @if (! empty($pay[$id]))
                                        <div class="mt-2 flex gap-2">
                                            @if ($fee['monthly'])
                                                <flux:input wire:model.live.blur="feeMonths.{{ $id }}" :label="__('Months')" type="number" min="1" :max="$fee['monthsLeft']" step="1" class="w-24" />
                                            @endif
                                            <flux:input wire:model.live.blur="feeAmounts.{{ $id }}" :label="__('Amount (Rp)')" type="number" min="1" :max="$fee['left']" step="1" class="flex-1" />
                                        </div>
                                    @endif
                                    <flux:error name="allocations.{{ $id }}" />
                                </div>
                            @endforeach
                        </div>
                        <flux:error name="allocations" />
                        <flux:error name="amount" />
                        <div class="flex items-center justify-between border-t border-zinc-200 pt-3 font-semibold dark:border-zinc-700">
                            <span>{{ __('Amount') }}</span>
                            <span class="tabular-nums" data-test="payment-total">{{ Money::format($this->total) }}</span>
                        </div>
                        <flux:radio.group wire:model.live="method" variant="segmented">
                            @foreach (PaymentMethod::cases() as $case)
                                <flux:radio :value="$case->value" :label="$case->label()" />
                            @endforeach
                        </flux:radio.group>
                        <flux:input wire:model="paidOn" :label="__('Paid on')" type="date" required />
                        @if ($this->administers && $this->cashAccounts->isNotEmpty())
                            <flux:select wire:model="cashAccountId" :label="__('Into account')" data-test="payment-account">
                                <flux:select.option value="">{{ __('— decide when verifying —') }}</flux:select.option>
                                @foreach ($this->cashAccounts as $account)
                                    <flux:select.option :value="$account->id">{{ $account->label() }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        @endif
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

            @if ($this->administers && $bill->status !== BillStatus::Void && ! $bill->semester->isReadOnly() && $bill->payments->whereIn('status', PaymentStatus::counting())->isEmpty())
                <flux:modal.trigger name="void-bill">
                    <flux:button variant="danger" size="sm" icon="no-symbol">{{ __('Void bill') }}</flux:button>
                </flux:modal.trigger>
            @endif
        </div>
    </div>

    @if ($this->administers)
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
    @endif

    <flux:modal name="override-installments" class="w-full max-w-md">
        <form wire:submit="overrideInstallments" class="space-y-6">
            <flux:heading size="lg">{{ __('Change instalment limit') }}</flux:heading>
            <flux:input wire:model="maxInstallments" :label="$overrideLineId !== null && isset($this->fees[$overrideLineId])
                ? __('Maximum instalments for :fee', ['fee' => $this->fees[$overrideLineId]['description']])
                : __('Maximum instalments for every fee of this bill')" type="number" min="1" max="12" required />
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
