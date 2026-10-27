<?php

use App\Actions\Finance\ManageCashBook;
use App\Enums\CashAccountType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\CashAccount;
use App\Models\CashCategory;
use App\Models\Payment;
use App\Models\Setting;
use App\Support\CashBook;
use App\Support\Money;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Accounts & categories')] class extends Component {
    // Account form.
    public ?int $accountId = null;
    public string $name = '';
    public string $type = 'cash';
    public ?string $accountNumber = null;
    public ?int $openingBalance = 0;
    public ?string $openingDate = null;
    public bool $isActive = true;

    public ?int $defaultCash = null;
    public ?int $defaultBank = null;

    // Category form.
    public ?int $categoryId = null;
    public string $categoryKind = 'expense';
    public string $categoryName = '';
    public bool $categoryActive = true;

    /** Unbooked payments: method => account id. */
    public array $assignTo = ['cash' => null, 'transfer' => null];

    public function mount(): void
    {
        $this->defaultCash = Setting::get('cashbook.default_cash_account');
        $this->defaultBank = Setting::get('cashbook.default_bank_account');
        $this->assignTo = ['cash' => $this->defaultCash, 'transfer' => $this->defaultBank];
    }

    /**
     * @return Collection<int, CashAccount>
     */
    #[Computed]
    public function accounts(): Collection
    {
        return CashAccount::query()->orderByDesc('is_active')->orderByDesc('type')->orderBy('name')->get();
    }

    /**
     * @return Collection<int, CashCategory>
     */
    #[Computed]
    public function categories(): Collection
    {
        return CashCategory::query()->orderBy('kind')->orderByDesc('is_active')->orderBy('name')->get();
    }

    /**
     * Verified tuition not booked to an account: method => [count, amount].
     *
     * @return array<string, array{count: int, amount: int}>
     */
    #[Computed]
    public function unbooked(): array
    {
        return Payment::query()->whereNull('cash_account_id')->where('status', PaymentStatus::Verified)->get(['method', 'amount'])
            ->groupBy(fn (Payment $payment) => $payment->method->value)
            ->map(fn ($group) => ['count' => $group->count(), 'amount' => (int) $group->sum('amount')])
            ->all();
    }

    public function createAccount(): void
    {
        $this->reset('accountId', 'name', 'accountNumber');
        $this->type = 'cash';
        $this->openingBalance = 0;
        $this->openingDate = now()->startOfMonth()->toDateString();
        $this->isActive = true;
        $this->resetErrorBag();
        Flux::modal('account-form')->show();
    }

    public function editAccount(int $id): void
    {
        $account = CashAccount::query()->findOrFail($id);
        $this->accountId = $account->id;
        $this->name = $account->name;
        $this->type = $account->type->value;
        $this->accountNumber = $account->account_number;
        $this->openingBalance = $account->opening_balance;
        $this->openingDate = $account->opening_date->toDateString();
        $this->isActive = $account->is_active;
        $this->resetErrorBag();
        Flux::modal('account-form')->show();
    }

    public function saveAccount(ManageCashBook $book): void
    {
        $this->resetErrorBag();
        $book->saveAccount($this->accountId ? CashAccount::query()->findOrFail($this->accountId) : null, [
            'name' => trim($this->name),
            'type' => $this->type,
            'account_number' => trim((string) $this->accountNumber) ?: null,
            'opening_balance' => $this->openingBalance,
            'opening_date' => $this->openingDate,
            'is_active' => $this->isActive,
        ]);

        Flux::modal('account-form')->close();
        unset($this->accounts);
        Flux::toast(variant: 'success', text: __('Account saved.'));
    }

    public function saveDefaults(ManageCashBook $book): void
    {
        $book->setDefaults($this->defaultCash ?: null, $this->defaultBank ?: null);
        Flux::toast(variant: 'success', text: __('Settings saved.'));
    }

    public function createCategory(string $kind): void
    {
        $this->reset('categoryId', 'categoryName');
        $this->categoryKind = $kind;
        $this->categoryActive = true;
        $this->resetErrorBag();
        Flux::modal('category-form')->show();
    }

    public function editCategory(int $id): void
    {
        $category = CashCategory::query()->findOrFail($id);
        $this->categoryId = $category->id;
        $this->categoryKind = $category->kind;
        $this->categoryName = $category->name;
        $this->categoryActive = $category->is_active;
        $this->resetErrorBag();
        Flux::modal('category-form')->show();
    }

    public function saveCategory(ManageCashBook $book): void
    {
        $this->resetErrorBag();
        $book->saveCategory($this->categoryId ? CashCategory::query()->findOrFail($this->categoryId) : null, $this->categoryKind, $this->categoryName, $this->categoryActive);
        Flux::modal('category-form')->close();
        unset($this->categories);
        Flux::toast(variant: 'success', text: __('Category saved.'));
    }

    public function assignUnbooked(string $method, ManageCashBook $book): void
    {
        $account = CashAccount::query()->where('is_active', true)->findOrFail((int) ($this->assignTo[$method] ?? 0));
        $count = $book->assignUnbooked(PaymentMethod::from($method), $account);
        unset($this->unbooked, $this->accounts);
        Flux::toast(variant: 'success', text: trans_choice(':count payment booked to :account.|:count payments booked to :account.', $count, ['account' => $account->name]));
    }
}; ?>

<section class="w-full max-w-5xl">
    <x-page-header :title="__('Accounts & categories')" :subtitle="__('Where the money is kept (cash and bank accounts, with their opening balance) and the categories of other income and expenses.')">
        <x-slot:actions>
            <flux:button size="sm" icon="book-open" :href="route('finance.cashbook')" wire:navigate>{{ __('Cash book') }}</flux:button>
        </x-slot:actions>
    </x-page-header>

    <div class="mb-3 flex items-center justify-between">
        <flux:heading size="lg">{{ __('Accounts') }}</flux:heading>
        <flux:button size="sm" variant="primary" icon="plus" wire:click="createAccount">{{ __('Add account') }}</flux:button>
    </div>
    <div class="mb-6 overflow-x-auto">
        <flux:table class="table-stack">
            <flux:table.columns>
                <flux:table.column>{{ __('Account') }}</flux:table.column>
                <flux:table.column>{{ __('Opening balance') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Balance now') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->accounts as $account)
                    <flux:table.row :key="$account->id" @class(['opacity-60' => ! $account->is_active])>
                        <flux:table.cell>
                            <span class="font-medium">{{ $account->name }}</span>
                            <div class="text-xs text-zinc-500">{{ $account->type->label() }}@if ($account->account_number) · {{ $account->account_number }}@endif @unless ($account->is_active) · {{ __('Inactive') }}@endunless</div>
                        </flux:table.cell>
                        <flux:table.cell class="text-sm">{{ Money::format($account->opening_balance) }} · {{ $account->opening_date->translatedFormat('j M Y') }}</flux:table.cell>
                        <flux:table.cell align="end" class="font-medium tabular-nums">{{ Money::format(CashBook::balance($account)) }}</flux:table.cell>
                        <flux:table.cell align="end">
                            <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="editAccount({{ $account->id }})" :aria-label="__('Edit')" />
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="4" class="text-center">{{ __('No accounts yet. Add the cash box (kas) and each bank account.') }}</flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>

    @if ($this->accounts->isNotEmpty())
        <flux:card class="mb-8 space-y-4">
            <flux:heading>{{ __('Default accounts for tuition payments') }}</flux:heading>
            <flux:text class="text-sm">{{ __('Preselected when a payment is recorded or verified; whoever records or verifies it can choose another account.') }}</flux:text>
            <form wire:submit="saveDefaults" class="grid items-end gap-4 sm:grid-cols-[1fr_1fr_auto]">
                <flux:select wire:model="defaultCash" :label="__('Cash payments')">
                    <flux:select.option value="">{{ __('— none —') }}</flux:select.option>
                    @foreach ($this->accounts->where('is_active', true) as $account)
                        <flux:select.option :value="$account->id">{{ $account->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model="defaultBank" :label="__('Bank transfers')">
                    <flux:select.option value="">{{ __('— none —') }}</flux:select.option>
                    @foreach ($this->accounts->where('is_active', true) as $account)
                        <flux:select.option :value="$account->id">{{ $account->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:button type="submit" data-test="save-defaults">{{ __('Save') }}</flux:button>
            </form>
        </flux:card>
    @endif

    @if ($this->unbooked !== [] && $this->accounts->isNotEmpty())
        <flux:card class="mb-8 space-y-4" data-test="unbooked">
            <div>
                <flux:heading>{{ __('Tuition payments not booked to an account') }}</flux:heading>
                <flux:text class="text-sm">{{ __('Verified before the cash book was set up. Book them to the account the money went into, so balances are right.') }}</flux:text>
            </div>
            @foreach ($this->unbooked as $method => $info)
                <div wire:key="unbooked-{{ $method }}" class="flex flex-wrap items-end gap-3">
                    <div class="min-w-48 text-sm">
                        <div class="font-medium">{{ PaymentMethod::from($method)->label() }}</div>
                        <div class="text-zinc-500">{{ trans_choice(':count payment|:count payments', $info['count']) }} · {{ Money::format($info['amount']) }}</div>
                    </div>
                    <flux:select wire:model="assignTo.{{ $method }}" size="sm" class="sm:max-w-64">
                        <flux:select.option value="">{{ __('Choose…') }}</flux:select.option>
                        @foreach ($this->accounts->where('is_active', true) as $account)
                            <flux:select.option :value="$account->id">{{ $account->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:button size="sm" wire:click="assignUnbooked('{{ $method }}')" wire:confirm="{{ __('Book these payments to the chosen account?') }}">{{ __('Book them') }}</flux:button>
                </div>
            @endforeach
        </flux:card>
    @endif

    <div class="grid gap-8 lg:grid-cols-2">
        @foreach (['income' => __('Income categories'), 'expense' => __('Expense categories')] as $kind => $heading)
            <div wire:key="categories-{{ $kind }}">
                <div class="mb-3 flex items-center justify-between">
                    <flux:heading size="lg">{{ $heading }}</flux:heading>
                    <flux:button size="sm" icon="plus" wire:click="createCategory('{{ $kind }}')">{{ __('Add') }}</flux:button>
                </div>
                <div class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                    @foreach ($this->categories->where('kind', $kind) as $category)
                        <div wire:key="category-{{ $category->id }}" @class(['flex items-center gap-3 px-4 py-2 text-sm', 'opacity-60' => ! $category->is_active])>
                            <span class="flex-1">{{ $category->name }}</span>
                            @unless ($category->is_active)<flux:badge size="sm">{{ __('Inactive') }}</flux:badge>@endunless
                            <flux:button size="xs" variant="ghost" icon="pencil-square" wire:click="editCategory({{ $category->id }})" :aria-label="__('Edit')" />
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>

    <flux:modal name="account-form" class="w-full max-w-md">
        <form wire:submit="saveAccount" class="space-y-5">
            <flux:heading size="lg">{{ $accountId ? __('Edit account') : __('Add account') }}</flux:heading>
            <flux:input wire:model="name" :label="__('Name')" :placeholder="__('e.g. Kas, BSI Operasional')" required />
            <flux:radio.group wire:model="type" variant="segmented">
                @foreach (CashAccountType::cases() as $case)
                    <flux:radio :value="$case->value" :label="$case->label()" />
                @endforeach
            </flux:radio.group>
            <flux:input wire:model="accountNumber" :label="__('Account number (optional)')" />
            <div class="grid gap-5 sm:grid-cols-2">
                <flux:input wire:model="openingBalance" :label="__('Opening balance (Rp)')" type="number" min="0" step="1" required />
                <flux:input wire:model="openingDate" :label="__('Opening date')" type="date" required />
            </div>
            <flux:text class="text-xs">{{ __('The amount in the account at the start of that day. The cash book counts only what happens from then on.') }}</flux:text>
            <flux:error name="opening_date" />
            <flux:checkbox wire:model="isActive" :label="__('Active')" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="save-account">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="category-form" class="w-full max-w-md">
        <form wire:submit="saveCategory" class="space-y-5">
            <flux:heading size="lg">{{ $categoryKind === 'income' ? __('Income category') : __('Expense category') }}</flux:heading>
            <flux:input wire:model="categoryName" :label="__('Name')" required />
            <flux:error name="name" />
            <flux:checkbox wire:model="categoryActive" :label="__('Active')" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
