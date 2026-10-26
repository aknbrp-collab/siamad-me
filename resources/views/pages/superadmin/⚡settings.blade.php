<?php

use App\Models\Setting;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('System settings')] class extends Component {
    public string $institutionName = '';
    public ?string $institutionAddress = null;
    public ?string $institutionPhone = null;
    public ?int $maxInstallments = null;
    public ?int $minInstallmentAmount = null;
    public ?string $receiptFooter = null;
    public ?int $attendanceMinimum = null;

    /**
     * Setting keys mapped to the component properties holding them.
     *
     * @var array<string, string>
     */
    private const FIELDS = [
        'institution.name' => 'institutionName',
        'institution.address' => 'institutionAddress',
        'institution.phone' => 'institutionPhone',
        'payment.max_installments' => 'maxInstallments',
        'payment.min_installment_amount' => 'minInstallmentAmount',
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
            'institutionName' => ['required', 'string', 'max:255'],
            'institutionAddress' => ['nullable', 'string', 'max:500'],
            'institutionPhone' => ['nullable', 'string', 'max:50'],
            'maxInstallments' => ['required', 'integer', 'min:1', 'max:12'],
            'minInstallmentAmount' => ['required', 'integer', 'min:0'],
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
    <flux:subheading>{{ __('Institution details and payment rules used across SIAKAD.') }}</flux:subheading>

    <form wire:submit="save" class="mt-6 space-y-8">
        <flux:fieldset>
            <flux:legend>{{ __('Institution') }}</flux:legend>

            <div class="space-y-6">
                <flux:input wire:model="institutionName" :label="__('Name')" required />
                <flux:textarea wire:model="institutionAddress" :label="__('Address')" rows="2" />
                <flux:input wire:model="institutionPhone" :label="__('Phone')" />
            </div>
        </flux:fieldset>

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
