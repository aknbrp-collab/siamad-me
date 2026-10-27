<?php

use App\Actions\Finance\ManageCashBook;
use App\Enums\PrintDocument;
use App\Models\CashAccount;
use App\Models\CashCategory;
use App\Models\CashEntry;
use App\Support\CashBook;
use App\Support\Money;
use App\Support\SpreadsheetDownload;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

new #[Title('Cash book')] class extends Component {
    use WithFileUploads;

    #[Url]
    public string $tab = 'ledger';

    #[Url(as: 'account')]
    public ?int $accountId = null;

    /** Ledger month, Y-m. */
    #[Url]
    public string $month = '';

    /** Report range, Y-m. */
    #[Url(as: 'from')]
    public string $fromMonth = '';

    #[Url(as: 'to')]
    public string $toMonth = '';

    // Entry form (other income / expense).
    public string $entryType = 'expense';
    public ?int $entryAccountId = null;
    public ?string $entryDate = null;
    public ?int $entryAmount = null;
    public ?int $entryCategoryId = null;
    public ?string $entryParty = null;
    public string $entryDescription = '';
    public $entryProof = null;

    // Transfer form.
    public ?int $fromAccountId = null;
    public ?int $toAccountId = null;
    public ?string $transferDate = null;
    public ?int $transferAmount = null;
    public string $transferDescription = '';

    // Void.
    public ?int $voidingId = null;
    public string $voidReason = '';

    public string $closeMonth = '';

    public function mount(): void
    {
        $this->accountId ??= CashAccount::query()->where('is_active', true)->orderByDesc('type')->orderBy('name')->value('id');
        $this->month = $this->month ?: now()->format('Y-m');
        $this->fromMonth = $this->fromMonth ?: now()->startOfYear()->format('Y-m');
        $this->toMonth = $this->toMonth ?: now()->format('Y-m');
        $this->closeMonth = now()->subMonth()->format('Y-m');
    }

    /**
     * @return Collection<int, CashAccount>
     */
    #[Computed]
    public function accounts(): Collection
    {
        return CashAccount::query()->orderByDesc('is_active')->orderByDesc('type')->orderBy('name')->get();
    }

    #[Computed]
    public function account(): ?CashAccount
    {
        return $this->accounts->firstWhere('id', $this->accountId);
    }

    /**
     * @return Collection<int, CashCategory>
     */
    #[Computed]
    public function categories(): Collection
    {
        return CashCategory::query()->where('is_active', true)->where('kind', $this->entryType)->orderBy('name')->get();
    }

    private function monthStart(string $month): Carbon
    {
        return preg_match('/^\d{4}-\d{2}$/', $month) ? Carbon::parse($month.'-01')->startOfMonth() : now()->startOfMonth();
    }

    /**
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function ledger(): ?array
    {
        $start = $this->monthStart($this->month);

        return $this->account === null ? null : CashBook::ledger($this->account, $start, $start->copy()->endOfMonth());
    }

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function summary(): array
    {
        return CashBook::summary($this->monthStart($this->fromMonth), $this->monthStart($this->toMonth)->endOfMonth());
    }

    #[Computed]
    public function closedThrough(): ?Carbon
    {
        return CashBook::closedThrough();
    }

    public function newEntry(string $type): void
    {
        $this->reset('entryAmount', 'entryCategoryId', 'entryParty', 'entryDescription', 'entryProof');
        $this->entryType = $type;
        $this->entryAccountId = $this->accountId;
        $this->entryDate = now()->toDateString();
        unset($this->categories);
        $this->resetErrorBag();
        Flux::modal('entry-form')->show();
    }

    public function saveEntry(ManageCashBook $book): void
    {
        $this->resetErrorBag();
        $book->record([
            'type' => $this->entryType,
            'cash_account_id' => $this->entryAccountId,
            'entry_date' => $this->entryDate,
            'amount' => $this->entryAmount,
            'cash_category_id' => $this->entryCategoryId,
            'party' => $this->entryParty,
            'description' => trim($this->entryDescription),
            'proof' => $this->entryProof,
        ], auth()->user());

        Flux::modal('entry-form')->close();
        unset($this->ledger, $this->summary);
        Flux::toast(variant: 'success', text: __('Entry recorded.'));
    }

    public function newTransfer(): void
    {
        $this->reset('toAccountId', 'transferAmount', 'transferDescription');
        $this->fromAccountId = $this->accountId;
        $this->transferDate = now()->toDateString();
        $this->resetErrorBag();
        Flux::modal('transfer-form')->show();
    }

    public function saveTransfer(ManageCashBook $book): void
    {
        $this->resetErrorBag();
        $book->transfer([
            'from_account_id' => $this->fromAccountId,
            'to_account_id' => $this->toAccountId,
            'entry_date' => $this->transferDate,
            'amount' => $this->transferAmount,
            'description' => trim($this->transferDescription),
        ], auth()->user());

        Flux::modal('transfer-form')->close();
        unset($this->ledger);
        Flux::toast(variant: 'success', text: __('Transfer recorded.'));
    }

    public function startVoid(int $id): void
    {
        $this->voidingId = CashEntry::query()->findOrFail($id)->id;
        $this->voidReason = '';
        $this->resetErrorBag();
        Flux::modal('void-entry')->show();
    }

    public function voidEntry(ManageCashBook $book): void
    {
        $this->resetErrorBag();
        $book->void(CashEntry::query()->findOrFail($this->voidingId), $this->voidReason, auth()->user());
        Flux::modal('void-entry')->close();
        unset($this->ledger, $this->summary);
        Flux::toast(variant: 'warning', text: __('Entry voided. It stays in the audit log.'));
    }

    public function closeBooks(ManageCashBook $book): void
    {
        $this->resetErrorBag();
        $end = $book->closeThrough($this->closeMonth);
        unset($this->closedThrough);
        Flux::toast(variant: 'success', text: __('Books closed through :date.', ['date' => $end->translatedFormat('F Y')]));
    }

    public function exportLedger(): ?BinaryFileResponse
    {
        if ($this->ledger === null) {
            return null;
        }

        $rows = collect($this->ledger['rows'])->map(fn (array $row) => [
            __('Date') => $row['date']->format('Y-m-d'),
            __('Number') => $row['number'],
            __('Category') => $row['category'],
            __('Description') => $row['description'],
            __('Party') => $row['party'],
            __('In') => $row['in'],
            __('Out') => $row['out'],
            __('Balance') => $row['balance'],
        ])->prepend([
            __('Date') => $this->monthStart($this->month)->format('Y-m-d'),
            __('Number') => '',
            __('Category') => '',
            __('Description') => __('Balance brought forward'),
            __('Party') => '',
            __('In') => '',
            __('Out') => '',
            __('Balance') => $this->ledger['start'],
        ]);

        return SpreadsheetDownload::make('buku-kas-'.str($this->account->name)->slug().'-'.$this->month.'.xlsx', $rows);
    }
}; ?>

<section class="w-full">
    <x-page-header :title="__('Cash book')" :subtitle="__('Money in and out of each account: tuition payments, other income, expenses and transfers between accounts, with the running balance.')">
        <x-slot:actions>
            <flux:button size="sm" icon="building-library" :href="route('finance.cash-accounts')" wire:navigate>{{ __('Accounts & categories') }}</flux:button>
        </x-slot:actions>
    </x-page-header>

    @if ($this->accounts->isEmpty())
        <flux:callout icon="information-circle" :text="__('Add the accounts first: the cash box (kas) and each bank account, with their opening balance.')">
            <x-slot name="actions"><flux:button size="sm" :href="route('finance.cash-accounts')" wire:navigate>{{ __('Accounts & categories') }}</flux:button></x-slot>
        </flux:callout>
    @else
        <flux:radio.group wire:model.live="tab" variant="segmented" class="mb-6">
            <flux:radio value="ledger" :label="__('Ledger')" icon="book-open" />
            <flux:radio value="report" :label="__('Report')" icon="chart-bar" />
        </flux:radio.group>

        @if ($tab === 'ledger')
            <div class="mb-4 flex flex-wrap items-end gap-3">
                <flux:select wire:model.live="accountId" :label="__('Account')" class="sm:max-w-64">
                    @foreach ($this->accounts as $item)
                        <flux:select.option :value="$item->id">{{ $item->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model.live="month" :label="__('Month')" type="month" class="sm:max-w-44" />
                <div class="ms-auto flex flex-wrap gap-2">
                    <flux:button size="sm" icon="arrow-down-left" wire:click="newEntry('income')" data-test="new-income">{{ __('Other income') }}</flux:button>
                    <flux:button size="sm" variant="primary" icon="arrow-up-right" wire:click="newEntry('expense')" data-test="new-expense">{{ __('Expense') }}</flux:button>
                    <flux:button size="sm" icon="arrows-right-left" wire:click="newTransfer" :disabled="$this->accounts->where('is_active', true)->count() < 2">{{ __('Transfer') }}</flux:button>
                    <flux:button size="sm" variant="ghost" icon="arrow-down-tray" wire:click="exportLedger">{{ __('Excel') }}</flux:button>
                </div>
            </div>

            @if ($this->ledger)
                <div class="mb-4 grid gap-3 sm:grid-cols-4">
                    @foreach ([__('Brought forward') => $this->ledger['start'], __('In') => $this->ledger['in'], __('Out') => $this->ledger['out'], __('Balance') => $this->ledger['end']] as $label => $value)
                        <flux:card class="p-4">
                            <flux:text class="text-xs">{{ $label }}</flux:text>
                            <div class="mt-1 text-lg font-semibold tabular-nums">{{ Money::format($value) }}</div>
                        </flux:card>
                    @endforeach
                </div>

                <div class="overflow-x-auto">
                    <flux:table class="table-stack">
                        <flux:table.columns>
                            <flux:table.column>{{ __('Date') }}</flux:table.column>
                            <flux:table.column>{{ __('Description') }}</flux:table.column>
                            <flux:table.column align="end">{{ __('In') }}</flux:table.column>
                            <flux:table.column align="end">{{ __('Out') }}</flux:table.column>
                            <flux:table.column align="end">{{ __('Balance') }}</flux:table.column>
                            <flux:table.column></flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @forelse ($this->ledger['rows'] as $index => $row)
                                <flux:table.row :key="'row-'.$index.'-'.$row['number']">
                                    <flux:table.cell class="text-sm">{{ $row['date']->translatedFormat('j M') }}</flux:table.cell>
                                    <flux:table.cell class="whitespace-normal">
                                        <div class="text-sm">{{ $row['description'] }}</div>
                                        <div class="text-xs text-zinc-500">
                                            <span class="font-mono">{{ $row['number'] }}</span> · {{ $row['category'] }}@if ($row['party']) · {{ $row['party'] }}@endif
                                        </div>
                                    </flux:table.cell>
                                    <flux:table.cell align="end" class="tabular-nums text-green-700 dark:text-green-400">{{ $row['in'] ? Money::format($row['in']) : '' }}</flux:table.cell>
                                    <flux:table.cell align="end" class="tabular-nums text-red-700 dark:text-red-400">{{ $row['out'] ? Money::format($row['out']) : '' }}</flux:table.cell>
                                    <flux:table.cell align="end" class="font-medium tabular-nums">{{ Money::format($row['balance']) }}</flux:table.cell>
                                    <flux:table.cell align="end">
                                        @if ($row['entry'])
                                            @if ($row['entry']->proof_path)
                                                <flux:button size="xs" variant="ghost" icon="paper-clip" :href="route('files.show', ['cash-proof', $row['entry']->id])" target="_blank" :aria-label="__('Proof')" />
                                            @endif
                                            <flux:button size="xs" variant="ghost" icon="no-symbol" wire:click="startVoid({{ $row['entry']->id }})" :aria-label="__('Void')" data-test="void-{{ $row['entry']->id }}" />
                                        @endif
                                    </flux:table.cell>
                                </flux:table.row>
                            @empty
                                <flux:table.row>
                                    <flux:table.cell colspan="6" class="text-center">{{ __('Nothing in this month.') }}</flux:table.cell>
                                </flux:table.row>
                            @endforelse
                        </flux:table.rows>
                    </flux:table>
                </div>
            @endif

            <flux:card class="mt-8 space-y-3">
                <flux:heading>{{ __('Close the books') }}</flux:heading>
                <flux:text class="text-sm">
                    {{ $this->closedThrough
                        ? __('Closed through :date. Entries on or before it can only be added or voided in maintenance mode.', ['date' => $this->closedThrough->translatedFormat('j F Y')])
                        : __('No month is closed yet. Close a month once it is checked, so its entries can no longer change.') }}
                </flux:text>
                <form wire:submit="closeBooks" class="flex flex-wrap items-end gap-3">
                    <flux:input wire:model="closeMonth" :label="__('Close through')" type="month" class="sm:max-w-44" />
                    <flux:button type="submit" icon="lock-closed" wire:confirm="{{ __('Close the books through this month?') }}" data-test="close-books">{{ __('Close') }}</flux:button>
                </form>
                <flux:error name="closeMonth" />
            </flux:card>
        @else
            <div class="mb-4 flex flex-wrap items-end gap-3">
                <flux:input wire:model.live="fromMonth" :label="__('From')" type="month" class="sm:max-w-44" />
                <flux:input wire:model.live="toMonth" :label="__('Until')" type="month" class="sm:max-w-44" />
                <div class="ms-auto">
                    <x-print-button size="sm" icon="printer" :href="route('finance.cash-report', ['from' => $fromMonth, 'to' => $toMonth])" :document="PrintDocument::CashReport">{{ __('Print report') }}</x-print-button>
                </div>
            </div>

            @if ($this->summary['unassigned'] > 0)
                <flux:callout icon="exclamation-triangle" color="amber" class="mb-4"
                    :text="__(':amount of tuition payments in this range are not booked to an account; balances leave them out.', ['amount' => Money::format($this->summary['unassigned'])])">
                    <x-slot name="actions"><flux:button size="sm" :href="route('finance.cash-accounts')" wire:navigate>{{ __('Book them') }}</flux:button></x-slot>
                </flux:callout>
            @endif

            <div class="grid gap-6 lg:grid-cols-2">
                <flux:card class="space-y-2">
                    <flux:heading>{{ __('Income') }}</flux:heading>
                    <div class="flex justify-between text-sm"><span>{{ __('Tuition payments') }}</span><span class="tabular-nums">{{ Money::format($this->summary['tuition']) }}</span></div>
                    @foreach ($this->summary['income'] as $name => $amount)
                        <div wire:key="income-{{ $name }}" class="flex justify-between text-sm"><span>{{ $name }}</span><span class="tabular-nums">{{ Money::format($amount) }}</span></div>
                    @endforeach
                    <div class="flex justify-between border-t border-zinc-200 pt-2 font-semibold dark:border-zinc-700"><span>{{ __('Total') }}</span><span class="tabular-nums">{{ Money::format($this->summary['total_in']) }}</span></div>
                </flux:card>
                <flux:card class="space-y-2">
                    <flux:heading>{{ __('Expenses') }}</flux:heading>
                    @forelse ($this->summary['expense'] as $name => $amount)
                        <div wire:key="expense-{{ $name }}" class="flex justify-between text-sm"><span>{{ $name }}</span><span class="tabular-nums">{{ Money::format($amount) }}</span></div>
                    @empty
                        <flux:text class="text-sm">{{ __('No expenses in this range.') }}</flux:text>
                    @endforelse
                    <div class="flex justify-between border-t border-zinc-200 pt-2 font-semibold dark:border-zinc-700"><span>{{ __('Total') }}</span><span class="tabular-nums">{{ Money::format($this->summary['total_out']) }}</span></div>
                </flux:card>
            </div>

            <flux:card class="mt-6 flex flex-wrap items-center justify-between gap-2">
                <flux:heading>{{ __('Surplus / deficit') }}</flux:heading>
                @php($net = $this->summary['total_in'] - $this->summary['total_out'])
                <span @class(['text-lg font-semibold tabular-nums', 'text-green-700 dark:text-green-400' => $net >= 0, 'text-red-700 dark:text-red-400' => $net < 0])>{{ Money::format($net) }}</span>
            </flux:card>
        @endif
    @endif

    <flux:modal name="entry-form" class="w-full max-w-lg">
        <form wire:submit="saveEntry" class="space-y-5">
            <flux:heading size="lg">{{ $entryType === 'income' ? __('Other income') : __('Expense') }}</flux:heading>
            <div class="grid gap-5 sm:grid-cols-2">
                <flux:select wire:model="entryAccountId" :label="$entryType === 'income' ? __('Into account') : __('From account')" required>
                    @foreach ($this->accounts->where('is_active', true) as $item)
                        <flux:select.option :value="$item->id">{{ $item->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="entryDate" :label="__('Date')" type="date" required />
                <flux:input wire:model="entryAmount" :label="__('Amount (Rp)')" type="number" min="1" step="1" required />
                <flux:select wire:model="entryCategoryId" :label="__('Category')" :placeholder="__('Choose…')" required>
                    @foreach ($this->categories as $category)
                        <flux:select.option :value="$category->id">{{ $category->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
            <flux:error name="entry_date" />
            <flux:error name="cash_category_id" />
            <flux:input wire:model="entryParty" :label="$entryType === 'income' ? __('Received from (optional)') : __('Paid to (optional)')" />
            <flux:textarea wire:model="entryDescription" :label="__('Description')" rows="2" required />
            <div>
                <flux:label>{{ __('Proof (receipt / kuitansi, optional)') }}</flux:label>
                <input type="file" wire:model="entryProof" accept=".pdf,.jpg,.jpeg,.png" class="mt-2 block w-full text-sm">
                <flux:error name="proof" />
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="save-entry">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="transfer-form" class="w-full max-w-lg">
        <form wire:submit="saveTransfer" class="space-y-5">
            <div>
                <flux:heading size="lg">{{ __('Transfer between accounts') }}</flux:heading>
                <flux:text class="mt-1">{{ __('e.g. depositing the cash box into the bank. Not income or expense: the money only moves.') }}</flux:text>
            </div>
            <div class="grid gap-5 sm:grid-cols-2">
                <flux:select wire:model="fromAccountId" :label="__('From account')" required>
                    @foreach ($this->accounts->where('is_active', true) as $item)
                        <flux:select.option :value="$item->id">{{ $item->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model="toAccountId" :label="__('To account')" :placeholder="__('Choose…')" required>
                    @foreach ($this->accounts->where('is_active', true) as $item)
                        <flux:select.option :value="$item->id">{{ $item->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="transferDate" :label="__('Date')" type="date" required />
                <flux:input wire:model="transferAmount" :label="__('Amount (Rp)')" type="number" min="1" step="1" required />
            </div>
            <flux:error name="to_account_id" />
            <flux:error name="entry_date" />
            <flux:input wire:model="transferDescription" :label="__('Description')" required />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="save-transfer">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="void-entry" class="w-full max-w-md">
        <form wire:submit="voidEntry" class="space-y-5">
            <div>
                <flux:heading size="lg">{{ __('Void entry') }}</flux:heading>
                <flux:text class="mt-1">{{ __('The entry stays in the book, marked void, and no longer counts. Both halves of a transfer are voided.') }}</flux:text>
            </div>
            <flux:textarea wire:model="voidReason" :label="__('Reason')" rows="2" required />
            <flux:error name="voidReason" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="danger" data-test="confirm-void">{{ __('Void') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
