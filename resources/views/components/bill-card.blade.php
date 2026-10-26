{{-- confirmable: show "Confirm transfer" (needs the ConfirmsTransfers trait on the page). --}}
@props(['bill', 'confirmable' => false])

@php
    use App\Enums\BillStatus;
    use App\Enums\PaymentSource;
    use App\Enums\PaymentStatus;
    use App\Support\Money;

    // From the loaded payments, as RecordPayment::allowance() counts them.
    $counting = $bill->payments->whereIn('status', PaymentStatus::counting());
    $canConfirm = $confirmable
        && $bill->status !== BillStatus::Void
        && $bill->net_amount - (int) $counting->sum('amount') > 0
        && $counting->count() < $bill->max_installments;
@endphp

<flux:card class="space-y-4">
    <div class="flex flex-wrap items-start justify-between gap-2">
        <div>
            <flux:heading>{{ $bill->semester->label() }}</flux:heading>
            <flux:text class="font-mono text-xs">{{ $bill->number }}@if ($bill->due_on) · {{ __('due :date', ['date' => $bill->due_on->translatedFormat('j M Y')]) }}@endif</flux:text>
        </div>
        <flux:badge :color="$bill->status->color()">{{ $bill->status->label() }}</flux:badge>
    </div>

    <div class="text-sm">
        @foreach ($bill->lines as $line)
            <div class="flex justify-between py-0.5">
                <span>{{ $line->description }}</span>
                <span @class(['tabular-nums', 'text-green-700 dark:text-green-400' => $line->amount < 0])>{{ Money::format($line->amount) }}</span>
            </div>
        @endforeach
        <div class="mt-1 flex justify-between border-t border-zinc-200 pt-1 font-semibold dark:border-zinc-700">
            <span>{{ __('Total') }}</span>
            <span class="tabular-nums">{{ Money::format($bill->net_amount) }}</span>
        </div>
        @if ($bill->status !== BillStatus::Void)
            <div class="flex justify-between font-medium">
                <span>{{ __('Still to pay') }}</span>
                <span class="tabular-nums">{{ Money::format($bill->outstanding()) }}</span>
            </div>
        @endif
    </div>

    {{-- Rejected confirmations the payer sent stay visible with the reason, so they can send it again. --}}
    @php($visible = $bill->payments->filter(fn ($payment) => in_array($payment->status, [PaymentStatus::Unverified, PaymentStatus::Verified, PaymentStatus::Void], true)
        || ($payment->status === PaymentStatus::Rejected && $payment->source === PaymentSource::Payer)))
    @if ($visible->isNotEmpty())
        <div class="space-y-1 border-t border-zinc-200 pt-3 text-sm dark:border-zinc-700">
            @foreach ($visible as $payment)
                <div wire:key="payment-{{ $payment->id }}" class="flex flex-wrap items-center justify-between gap-2">
                    <span>{{ $payment->paid_on->translatedFormat('j M Y') }} · {{ $payment->method->label() }}</span>
                    <span class="flex items-center gap-2">
                        <span class="tabular-nums">{{ Money::format($payment->amount) }}</span>
                        <flux:badge size="sm" :color="$payment->status->color()">{{ $payment->status->label() }}</flux:badge>
                        @if ($payment->status === PaymentStatus::Verified)
                            <flux:link :href="route('receipts.show', $payment)" target="_blank" class="text-xs">{{ __('Receipt') }}</flux:link>
                        @endif
                    </span>
                    @if ($payment->status === PaymentStatus::Rejected && $payment->status_note)
                        <flux:text class="w-full text-xs text-red-600 dark:text-red-400">{{ __('Rejected: :reason', ['reason' => $payment->status_note]) }}</flux:text>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    @if ($canConfirm)
        <div class="border-t border-zinc-200 pt-3 dark:border-zinc-700">
            <flux:button size="sm" icon="building-library" wire:click="openTransfer({{ $bill->id }})" data-test="confirm-transfer-{{ $bill->id }}">
                {{ __('Confirm transfer') }}
            </flux:button>
        </div>
    @endif
</flux:card>
