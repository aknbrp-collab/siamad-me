<?php

use App\Enums\BillStatus;
use App\Enums\HandoverStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Bill;
use App\Models\ClassEnrollment;
use App\Models\Payment;
use App\Models\PaymentHandover;
use App\Models\Semester;
use App\Support\ActiveRole;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Finance')] class extends Component {
    #[Url(as: 'semester')]
    public ?int $semesterId = null;

    public function mount(): void
    {
        $this->semesterId ??= Semester::active()?->id ?? Semester::query()->orderByDesc('id')->value('id');
    }

    /**
     * @return Collection<int, Semester>
     */
    #[Computed]
    public function semesters(): Collection
    {
        return Semester::query()->with('academicYear')->orderByDesc('academic_year_id')->orderByDesc('term')->get();
    }

    /**
     * @return Collection<int, Bill>
     */
    #[Computed]
    public function bills(): Collection
    {
        return Bill::query()
            ->with('student.user')
            ->where('semester_id', $this->semesterId)
            ->where('status', '!=', BillStatus::Void)
            ->get();
    }

    /**
     * @return array{billed: int, collected: int, pending: int, outstanding: int, counts: array<string, int>}
     */
    #[Computed]
    public function totals(): array
    {
        $pending = (int) Payment::query()
            ->where('status', PaymentStatus::Unverified)
            ->whereIn('bill_id', $this->bills->modelKeys())
            ->sum('amount');

        return [
            'billed' => (int) $this->bills->sum('net_amount'),
            'collected' => (int) $this->bills->sum('paid_amount'),
            'pending' => $pending,
            'outstanding' => (int) $this->bills->sum(fn (Bill $bill) => $bill->outstanding()),
            'counts' => $this->bills->countBy(fn (Bill $bill) => $bill->status->value)->all(),
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<int, array{class: string, bills: int, billed: int, collected: int, outstanding: int}>
     */
    #[Computed]
    public function byClass(): \Illuminate\Support\Collection
    {
        $classOf = ClassEnrollment::query()->where('semester_id', $this->semesterId)->pluck('class_name', 'student_id');

        return $this->bills
            ->groupBy(fn (Bill $bill) => $classOf[$bill->student_id] ?? __('No class'))
            ->map(fn ($bills, $class) => [
                'class' => (string) $class,
                'bills' => $bills->count(),
                'billed' => (int) $bills->sum('net_amount'),
                'collected' => (int) $bills->sum('paid_amount'),
                'outstanding' => (int) $bills->sum(fn (Bill $bill) => $bill->outstanding()),
            ])
            ->sortBy('class')
            ->values();
    }

    /**
     * @return \Illuminate\Support\Collection<int, Bill>
     */
    #[Computed]
    public function arrears(): \Illuminate\Support\Collection
    {
        return $this->bills
            ->filter(fn (Bill $bill) => $bill->outstanding() > 0)
            ->sortByDesc(fn (Bill $bill) => $bill->outstanding())
            ->take(10)
            ->values();
    }

    /**
     * @return array{handovers: int, cash: int, transfers: int}
     */
    #[Computed]
    public function waiting(): array
    {
        return [
            'handovers' => PaymentHandover::query()->where('status', HandoverStatus::Submitted)->count(),
            'cash' => Payment::query()->where('status', PaymentStatus::Unverified)->where('method', PaymentMethod::Cash)->whereNull('payment_handover_id')->count(),
            'transfers' => Payment::query()->where('status', PaymentStatus::Unverified)->where('method', PaymentMethod::Transfer)->count(),
        ];
    }
}; ?>

<section class="w-full space-y-8">
    <x-page-header :title="__('Finance overview')" :subtitle="ActiveRole::get()?->label().' · '.\App\Models\Setting::get('institution.name')">
        <x-slot:actions>
            <flux:select wire:model.live="semesterId" class="w-60">
                @foreach ($this->semesters as $semester)
                    <flux:select.option :value="$semester->id">{{ $semester->label() }}</flux:select.option>
                @endforeach
            </flux:select>
        </x-slot:actions>
    </x-page-header>

    @php($balances = \App\Support\CashBook::balances())
    @if ($balances->isNotEmpty())
        <div data-test="account-balances">
            <div class="mb-3 flex items-center justify-between">
                <flux:heading size="lg">{{ __('Account balances') }}</flux:heading>
                <flux:button size="sm" variant="ghost" icon="book-open" :href="route('finance.cashbook')" wire:navigate>{{ __('Cash book') }}</flux:button>
            </div>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($balances as $row)
                    <flux:card wire:key="balance-{{ $row['account']->id }}" class="p-4">
                        <flux:text class="text-xs">{{ $row['account']->label() }}</flux:text>
                        <div class="mt-1 text-lg font-semibold tabular-nums">{{ Money::format($row['balance']) }}</div>
                    </flux:card>
                @endforeach
            </div>
        </div>
    @endif

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ([
            ['label' => __('Billed'), 'value' => $this->totals['billed'], 'hint' => trans_choice(':count bill|:count bills', $this->bills->count())],
            ['label' => __('Collected (verified)'), 'value' => $this->totals['collected'], 'hint' => $this->totals['billed'] > 0 ? round($this->totals['collected'] / $this->totals['billed'] * 100).'%' : '—'],
            ['label' => __('Awaiting verification'), 'value' => $this->totals['pending'], 'hint' => __('not counted yet')],
            ['label' => __('Outstanding'), 'value' => $this->totals['outstanding'], 'hint' => trans_choice(':count bill unpaid or partly paid|:count bills unpaid or partly paid', ($this->totals['counts']['unpaid'] ?? 0) + ($this->totals['counts']['partial'] ?? 0))],
        ] as $stat)
            <flux:card class="space-y-1">
                <flux:text class="text-sm">{{ $stat['label'] }}</flux:text>
                <div class="text-2xl font-semibold tabular-nums">{{ Money::format($stat['value']) }}</div>
                <flux:text class="text-xs">{{ $stat['hint'] }}</flux:text>
            </flux:card>
        @endforeach
    </div>

    @if ($this->waiting['cash'] + $this->waiting['handovers'] + $this->waiting['transfers'] > 0)
        <flux:callout icon="clock" color="amber">
            <flux:callout.text>
                {{ __(':cash cash payments not handed over · :handovers handovers and :transfers transfers awaiting the Admin.', $this->waiting) }}
            </flux:callout.text>
            <x-slot name="actions">
                <flux:button size="sm" :href="route('finance.handovers')" wire:navigate>{{ __('Cash handovers') }}</flux:button>
            </x-slot>
        </flux:callout>
    @endif

    <div class="grid gap-8 xl:grid-cols-2">
        <div>
            <flux:heading class="mb-2">{{ __('By class') }}</flux:heading>
            <div class="overflow-x-auto">
                <flux:table class="table-stack">
                    <flux:table.columns>
                        <flux:table.column>{{ __('Class') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Billed') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Collected') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Outstanding') }}</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @forelse ($this->byClass as $row)
                            <flux:table.row :key="$row['class']">
                                <flux:table.cell class="font-medium">{{ $row['class'] }} <span class="text-zinc-500">({{ $row['bills'] }})</span></flux:table.cell>
                                <flux:table.cell align="end" class="tabular-nums">{{ Money::format($row['billed']) }}</flux:table.cell>
                                <flux:table.cell align="end" class="tabular-nums">{{ Money::format($row['collected']) }}</flux:table.cell>
                                <flux:table.cell align="end" class="tabular-nums">{{ Money::format($row['outstanding']) }}</flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell colspan="4" class="text-center">{{ __('No bills for this semester yet.') }}</flux:table.cell>
                            </flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            </div>
        </div>

        <div>
            <flux:heading class="mb-2">{{ __('Largest arrears') }}</flux:heading>
            <div class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                @forelse ($this->arrears as $bill)
                    <a wire:key="arrear-{{ $bill->id }}" href="{{ route('finance.bills.show', $bill) }}" wire:navigate
                        class="flex items-center justify-between gap-3 px-3 py-2 text-sm hover:bg-zinc-50 dark:hover:bg-zinc-800">
                        <span>
                            <span class="font-medium">{{ $bill->student->user->name }}</span>
                            <span class="text-zinc-500">· {{ $bill->number }}</span>
                        </span>
                        <span class="flex items-center gap-2">
                            <flux:badge size="sm" :color="$bill->status->color()">{{ $bill->status->label() }}</flux:badge>
                            <span class="tabular-nums">{{ Money::format($bill->outstanding()) }}</span>
                        </span>
                    </a>
                @empty
                    <flux:text class="p-3 text-sm">{{ __('No arrears.') }}</flux:text>
                @endforelse
            </div>
        </div>
    </div>
</section>
