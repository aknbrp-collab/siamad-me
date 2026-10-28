{{-- Requires the ConfirmsTransfers trait on the component. --}}
@php($allowance = $this->transferAllowance)

<flux:modal name="confirm-transfer" class="w-full max-w-md">
    @if ($this->confirmingBill && $allowance)
        <form wire:submit="submitTransfer" class="space-y-5">
            <div>
                <flux:heading size="lg">{{ __('Confirm transfer') }}</flux:heading>
                <flux:text class="mt-1">{{ $this->confirmingBill->semester->label() }} · <span class="font-mono">{{ $this->confirmingBill->number }}</span></flux:text>
            </div>

            @if ($this->bankAccounts)
                <div class="rounded-lg bg-zinc-50 p-3 text-sm dark:bg-zinc-800">
                    <div class="mb-1 font-medium">{{ __('Transfer to') }}</div>
                    <div class="whitespace-pre-line font-mono">{{ $this->bankAccounts }}</div>
                </div>
            @endif

            <flux:text class="text-sm">
                {{ __('Open: :open · instalment :next of :max · minimum :min unless it settles the rest.', [
                    'open' => \App\Support\Money::format($allowance['open']),
                    'next' => $allowance['used'] + 1,
                    'max' => $allowance['max'],
                    'min' => \App\Support\Money::format($allowance['min']),
                ]) }}
            </flux:text>

            <flux:input wire:model="transferAmount" :label="__('Amount (Rp)')" type="number" min="1" step="1" required />
            <flux:error name="amount" />

            <flux:input wire:model="transferPaidOn" :label="__('Transfer date')" type="date" required />
            <flux:error name="paid_on" />

            <flux:input wire:model="transferReference" :label="__('Sender name / bank reference')" :placeholder="__('e.g. BSI, a.n. Abdullah')" required />
            <flux:error name="reference" />

            <div>
                <flux:label>{{ __('Bank receipt') }}</flux:label>
                <input type="file" wire:model="transferProof" accept="image/*,application/pdf" class="mt-2 block text-sm file:me-3 file:rounded-md file:border-0 file:bg-zinc-100 file:px-3 file:py-1.5 dark:file:bg-zinc-700">
                <flux:text class="mt-1 text-xs">{{ __('PDF, JPG or PNG, up to :size MB.', ['size' => \App\Support\Upload::maxMb()]) }}</flux:text>
                <flux:error name="proof" />
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="send-transfer">{{ __('Send') }}</flux:button>
            </div>
        </form>
    @endif
</flux:modal>
