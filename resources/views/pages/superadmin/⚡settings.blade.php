<?php

use App\Models\Setting;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('System settings')] class extends Component {
    public ?int $maxInstallments = null;
    public ?int $minInstallmentAmount = null;
    public ?string $bankAccounts = null;
    public ?string $receiptFooter = null;
    public ?int $attendanceMinimum = null;

    /**
     * Setting keys mapped to the component properties holding them.
     *
     * @var array<string, string>
     */
    private const FIELDS = [
        'payment.max_installments' => 'maxInstallments',
        'payment.min_installment_amount' => 'minInstallmentAmount',
        'payment.bank_accounts' => 'bankAccounts',
        'receipt.footer' => 'receiptFooter',
        'attendance.min_percent' => 'attendanceMinimum',
    ];

    public function mount(): void
    {
        foreach (self::FIELDS as $key => $property) {
            $this->{$property} = Setting::get($key);
        }
    }

    /**
     * @return array<string, list<string>>
     */
    protected function rules(): array
    {
        return [
            'maxInstallments' => ['required', 'integer', 'min:1', 'max:12'],
            'minInstallmentAmount' => ['required', 'integer', 'min:0'],
            'bankAccounts' => ['nullable', 'string', 'max:1000'],
            'receiptFooter' => ['nullable', 'string', 'max:500'],
            'attendanceMinimum' => ['required', 'integer', 'between:0,100'],
        ];
    }

    public function save(): void
    {
        $this->validate();

        foreach (self::FIELDS as $key => $property) {
            $value = $this->{$property} === '' ? null : $this->{$property};

            if (Setting::get($key) !== $value) {
                Setting::set($key, $value);
            }
        }

        Flux::toast(variant: 'success', text: __('Settings saved.'));
    }
}; ?>

<section class="w-full max-w-2xl">
    <flux:heading size="xl" level="1">{{ __('System settings') }}</flux:heading>
    <flux:subheading>{{ __('Payment and academic rules used across SIAKAD. The details and logos of the institution are under Identity.') }}</flux:subheading>

    <form wire:submit="save" class="mt-6 space-y-8">
        <flux:fieldset>
            <flux:legend>{{ __('Payments') }}</flux:legend>

            <div class="grid gap-6 sm:grid-cols-2">
                <flux:input
                    wire:model="maxInstallments"
                    :label="__('Maximum instalments per bill')"
                    type="number"
                    min="1"
                    max="12"
                    required
                />
                <flux:input
                    wire:model="minInstallmentAmount"
                    :label="__('Minimum instalment (Rp)')"
                    :description:trailing="__('A payment that settles the remaining balance may be smaller.')"
                    type="number"
                    min="0"
                    step="1"
                    required
                />
            </div>

            <div class="mt-6">
                <flux:textarea wire:model="bankAccounts" :label="__('Bank accounts for transfers')" rows="3"
                    placeholder="BSI 7123456789 a.n. Yayasan Ma'had Aly"
                    :description:trailing="__('Shown to mahasantri and wali santri when they confirm a transfer. One account per line.')" />
            </div>

            <div class="mt-6">
                <flux:textarea wire:model="receiptFooter" :label="__('Receipt footer')" rows="2" />
            </div>
        </flux:fieldset>

        <flux:fieldset>
            <flux:legend>{{ __('Academic') }}</flux:legend>
            <flux:input
                wire:model="attendanceMinimum"
                :label="__('Minimum attendance (%)')"
                :description:trailing="__('Mahasantri below this share of “hadir” are flagged for the academic monitor.')"
                type="number"
                min="0"
                max="100"
                required
                class="sm:max-w-xs"
            />
        </flux:fieldset>

        <flux:button variant="primary" type="submit" data-test="save-settings-button">{{ __('Save') }}</flux:button>
    </form>
</section>
