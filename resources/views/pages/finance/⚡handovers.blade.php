<?php

use App\Actions\Finance\ReviewPayments;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\PaymentHandover;
use App\Support\Money;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Cash handovers')] class extends Component {
    use WithPagination;

    /** @var list<int> */
    public array $selected = [];

    public ?string $note = null;

    /**
     * My recorded cash that has not been handed over yet.
     *
     * @return Collection<int, Payment>
     */
    #[Computed]
    public function pending(): Collection
    {
        return Payment::query()
            ->with('bill.student.user')
            ->where('recorded_by', auth()->id())
            ->where('method', PaymentMethod::Cash)
            ->where('status', PaymentStatus::Unverified)
            ->whereNull('payment_handover_id')
            ->orderBy('paid_on')
            ->get();
    }

    #[Computed]
    public function selectedTotal(): int
    {
        return (int) $this->pending->whereIn('id', $this->selected)->sum('amount');
    }

    /**
     * @return LengthAwarePaginator<int, PaymentHandover>
     */
    #[Computed]
    public function handovers(): LengthAwarePaginator
    {
        return PaymentHandover::query()
            ->with(['handedOverBy', 'receivedBy'])
            ->withCount('payments')
            ->where('handed_over_by', auth()->id())
            ->orderByDesc('id')
            ->paginate(15);
    }

    public function selectAll(): void
    {
        $this->selected = $this->pending->modelKeys();
    }

    public function handOver(ReviewPayments $review): void
    {
        $this->resetErrorBag();

        $handover = $review->handOver(auth()->user(), array_map('intval', $this->selected), $this->note ?: null);

        $this->reset('selected', 'note');
        unset($this->pending, $this->handovers);
        Flux::toast(variant: 'success', text: __('Handover :number created for :amount. Give the cash to the Admin.', [
            'number' => $handover->number,
            'amount' => Money::format($handover->total_amount),
        ]));
    }
}; ?>

<section class="w-full">
    <x-page-header :title="__('Cash handovers')" :subtitle="__('Bundle the cash you collected and hand it to the Admin, who verifies each payment. Payments only count after verification.')" />

    @error('handover')
        <flux:callout variant="danger" icon="exclamation-triangle" class="mb-6" :text="$message" />
    @enderror

    <flux:card class="mb-8 space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <flux:heading>{{ __('Cash not handed over yet') }}</flux:heading>
            @if ($this->pending->isNotEmpty())
                <flux:button size="sm" variant="ghost" wire:click="selectAll">{{ __('Select all') }}</flux:button>
            @endif
        </div>

        @if ($this->pending->isEmpty())
            <flux:text class="text-sm">{{ __('Nothing to hand over.') }}</flux:text>
        @else
            <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach ($this->pending as $payment)
                    <label wire:key="pending-{{ $payment->id }}" class="flex cursor-pointer items-center gap-3 py-2 text-sm">
                        <flux:checkbox wire:model.live="selected" :value="$payment->id" />
                        <span class="flex-1">
                            <span class="font-medium">{{ $payment->bill->student->user->name }}</span>
                            <span class="text-zinc-500">· {{ $payment->bill->number }} · {{ $payment->paid_on->translatedFormat('j M') }}</span>
                        </span>
                        <span class="tabular-nums">{{ Money::format($payment->amount) }}</span>
                    </label>
                @endforeach
            </div>

            <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                <flux:input wire:model="note" :label="__('Note (optional)')" class="flex-1" />
                <flux:button variant="primary" wire:click="handOver" :disabled="$selected === []" data-test="create-handover">
                    {{ __('Hand over :amount', ['amount' => Money::format($this->selectedTotal)]) }}
                </flux:button>
            </div>
        @endif
    </flux:card>

    <flux:heading class="mb-2">{{ __('My handovers') }}</flux:heading>
    <div class="overflow-x-auto">
        <flux:table :paginate="$this->handovers">
            <flux:table.columns>
                <flux:table.column>{{ __('Number') }}</flux:table.column>
                <flux:table.column>{{ __('Submitted') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Total') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->handovers as $handover)
                    <flux:table.row :key="$handover->id">
                        <flux:table.cell class="font-mono text-sm">{{ $handover->number }}</flux:table.cell>
                        <flux:table.cell>{{ $handover->submitted_at->translatedFormat('j M Y H:i') }} · {{ trans_choice(':count payment|:count payments', $handover->payments_count) }}</flux:table.cell>
                        <flux:table.cell align="end" class="tabular-nums">{{ Money::format($handover->total_amount) }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm" :color="$handover->status->color()">{{ $handover->status->label() }}</flux:badge>
                            @if ($handover->receivedBy)
                                <span class="text-xs text-zinc-500">{{ $handover->receivedBy->name }}</span>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="4" class="text-center">{{ __('No handovers yet.') }}</flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>
</section>
