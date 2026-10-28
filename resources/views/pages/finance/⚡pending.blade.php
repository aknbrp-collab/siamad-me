<?php

use App\Support\Money;
use App\Support\PendingPayments;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Unverified payments')] class extends Component {
    #[Url]
    public string $stage = '';

    /** A holder group from the summary (stage:user or stage:roles). */
    #[Url]
    public string $holder = '';

    /**
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function rows(): Collection
    {
        return PendingPayments::rows();
    }

    /**
     * @return Collection<int, array{key: string, stage: string, holder: string, count: int, amount: int, days: int}>
     */
    #[Computed]
    public function summary(): Collection
    {
        return PendingPayments::summary($this->rows);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function shown(): Collection
    {
        return $this->rows
            ->when($this->stage !== '', fn (Collection $rows) => $rows->where('stage', $this->stage))
            ->when($this->holder !== '', fn (Collection $rows) => $rows->filter(fn (array $row) => PendingPayments::holderKey($row) === $this->holder))
            ->values();
    }

    public function updatedStage(): void
    {
        $this->holder = '';
    }

    public function show(string $key): void
    {
        $this->holder = $this->holder === $key ? '' : $key;
        $this->stage = '';
        unset($this->shown);
    }

    public function remind(string $key): void
    {
        $sent = PendingPayments::remind($key, auth()->user());

        Flux::toast(
            variant: $sent > 0 ? 'success' : 'warning',
            text: $sent > 0
                ? trans_choice('Reminder sent to :count person.|Reminder sent to :count people.', $sent)
                : __('Already reminded today, or nobody to remind.'),
        );
    }
}; ?>

<section class="w-full">
    <x-page-header :title="__('Unverified payments')" :subtitle="__('Money that is recorded but not verified yet, where it is waiting and with whom.')" />

    @if ($this->rows->isEmpty())
        <flux:callout icon="check-circle" color="green" :text="__('Every recorded payment is verified.')" />
    @else
        <div class="mb-8 grid gap-4 sm:grid-cols-3">
            @foreach (PendingPayments::stages() as $key => $label)
                @php($inStage = $this->rows->where('stage', $key))
                <flux:card class="space-y-1">
                    <flux:text class="text-sm">{{ $label }}</flux:text>
                    <div class="text-2xl font-semibold tabular-nums">{{ Money::format((int) $inStage->sum(fn ($row) => $row['payment']->amount)) }}</div>
                    <flux:text class="text-xs">{{ trans_choice(':count payment|:count payments', $inStage->count()) }}</flux:text>
                </flux:card>
            @endforeach
        </div>

        <flux:heading class="mb-1">{{ __('Who has them') }}</flux:heading>
        <flux:text class="mb-3 text-sm">{{ __('Each line is one person or group the payments are waiting with. Remind them, at most once a day.') }}</flux:text>
        <div class="mb-8 divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
            @foreach ($this->summary as $group)
                <div wire:key="holder-{{ $group['key'] }}" @class(['flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center', 'bg-zinc-50 dark:bg-zinc-800' => $holder === $group['key']])>
                    <div class="min-w-0 flex-1">
                        <div class="font-medium">{{ $group['holder'] }}</div>
                        <div class="text-sm text-zinc-500">
                            {{ PendingPayments::stages()[$group['stage']] }} · {{ trans_choice(':count payment|:count payments', $group['count']) }} · {{ Money::format($group['amount']) }}
                        </div>
                    </div>
                    <flux:badge size="sm" :color="$group['days'] >= 7 ? 'red' : ($group['days'] >= 3 ? 'amber' : 'zinc')">
                        {{ trans_choice('oldest :count day|oldest :count days', $group['days']) }}
                    </flux:badge>
                    <div class="flex gap-2">
                        <flux:button size="sm" variant="ghost" wire:click="show('{{ $group['key'] }}')">{{ $holder === $group['key'] ? __('Show all') : __('Show') }}</flux:button>
                        <flux:button size="sm" icon="bell" wire:click="remind('{{ $group['key'] }}')" data-test="remind-{{ $group['key'] }}">{{ __('Remind') }}</flux:button>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mb-3 flex flex-wrap items-center gap-3">
            <flux:heading class="me-auto">{{ __('Payments') }}</flux:heading>
            <flux:select wire:model.live="stage" class="sm:max-w-60" size="sm">
                <flux:select.option value="">{{ __('All stages') }}</flux:select.option>
                @foreach (PendingPayments::stages() as $key => $label)
                    <flux:select.option :value="$key">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
        @php($canOpenBills = in_array(\App\Support\ActiveRole::get(), [\App\Enums\Role::AdminPayment, \App\Enums\Role::Superadmin], true))
        <div class="overflow-x-auto">
            <flux:table class="table-stack">
                <flux:table.columns>
                    <flux:table.column>{{ __('Mahasantri') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Amount') }}</flux:table.column>
                    <flux:table.column>{{ __('Stuck at') }}</flux:table.column>
                    <flux:table.column>{{ __('From') }}</flux:table.column>
                    <flux:table.column>{{ __('Waiting with') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Days') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse ($this->shown as $row)
                        <flux:table.row :key="$row['payment']->id">
                            <flux:table.cell>
                                @if ($canOpenBills)
                                    <a href="{{ route('finance.bills.show', $row['payment']->bill) }}" wire:navigate class="font-medium hover:underline">{{ $row['payment']->bill->student->user->name }}</a>
                                @else
                                    <span class="font-medium">{{ $row['payment']->bill->student->user->name }}</span>
                                @endif
                                <div class="text-xs text-zinc-500">{{ $row['payment']->bill->number }} · {{ $row['payment']->method->label() }} · {{ $row['payment']->paid_on->translatedFormat('j M Y') }}</div>
                            </flux:table.cell>
                            <flux:table.cell align="end" class="tabular-nums">{{ Money::format($row['payment']->amount) }}</flux:table.cell>
                            <flux:table.cell>
                                {{ PendingPayments::stages()[$row['stage']] }}
                                @if ($row['payment']->handover)
                                    <div class="font-mono text-xs text-zinc-500">{{ $row['payment']->handover->number }}</div>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                {{ $row['from']->name }}
                                <div class="text-xs text-zinc-500">{{ $row['since']->translatedFormat('j M Y H:i') }}</div>
                            </flux:table.cell>
                            <flux:table.cell>{{ $row['holder'] }}</flux:table.cell>
                            <flux:table.cell align="end" class="tabular-nums">{{ $row['days'] }}</flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="6" class="text-center">{{ __('No payments at this stage.') }}</flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </div>
    @endif
</section>
